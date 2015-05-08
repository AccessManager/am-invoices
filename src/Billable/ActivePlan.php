<?php
namespace AccessManager\Invoices\Billable;
use AccessManager\Invoices\Interfaces\BillableInterface;
use AccessManager\Invoices\Helpers\Database;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class ActivePlan implements BillableInterface {

	private $plan;
	private $invoice;
	private $startDate  		= NULL;
	private $stopDate 			= NULL;
	private $months 			= 0;
	private $days 				= 0;
	private $costPerDay 		= 0;
	private $amount 			= 0;
	private $tax 				= 0;
	private $durationCalculated = FALSE;

	public function startDate()
	{
		return $this->startDate->format('Y-m-d');
	}

	public function stopDate()
	{
		return $this->invoice->invoiceStopDate();
	}

	public function amount()
	{
		$this->_calculateDuration();
		$this->_calculateCostPerDay();
		$this->_calculateAmount();
		$this->_calculateTax();
		return $this->amount;
	}

	private function _calculateAmount()
	{
		$this->amount = ($this->days * $this->costPerDay) + ($this->plan->price * $this->months) + $this->tax;
	}

	private function _calculateTax()
	{
		$this->tax = ($this->amount * 12.36) / 100;
	}

	private function _calculateCostPerDay()
	{
		$cost_per_day = $this->plan->price / 30;
		$this->costPerDay = number_format( (float) $cost_per_day,2,'.','');
	}

	private function _calculateDuration()
	{
		if( $this->durationCalculated )		return;
		$stopDate = clone $this->stopDate;
		$stopDate->addDay();
		
		$d = $stopDate->diff($this->startDate);

		if( $d->format('%m') > 0 ) {
			while( $stopDate > $this->startDate ) {	
				$stopDate->subMonth();
				$this->months++;
				
				$startMonth = $this->startDate->format('m');
				if ($stopDate->format('m') <= ++$startMonth )
					break;
			}
		}
		
		$diff = $stopDate->diff($this->startDate);
		
		$this->days = $diff->format('%d');

		echo "<br />",
			"Plan Duration Start Date: ", $this->startDate->format('Y-m-d'),
			'<br/>',
			'Plan Duration Stop Date: ', $stopDate->format('Y-m-d'), "<br />"
		;
		echo $this->months . "Months " . $this->days . "Days. <br />";
	}

	public function addToInvoice()
	{
		if($this->stopDate < $this->startDate)	return;
		
		DB::table('ap_invoice_plans')
			->insert([
						'invoice_id'	=>	$this->invoice->id(),
						'plan_name'		=>	$this->plan->plan_name,
						'billed_from'	=>	$this->startDate(),
						'billed_till'	=>	$this->stopDate(),
						'amount'		=>	$this->amount(),
				]);
	}

	public function __construct($activePlan, $invoice)
	{
		$this->plan = $activePlan;
		$this->invoice = $invoice;
		$this->_makeStartStopDates();
		Database::connect();
	}

	private function _makeStartStopDates()
	{
		$invoiceStartDate = $this->invoice->invoiceStartDateObject();
		$planStartDate = new Carbon(date('Y-m-d', strtotime($this->plan->assigned_on)));
		$this->startDate = $planStartDate < $invoiceStartDate ? $invoiceStartDate : $planStartDate;
		$this->stopDate = $this->invoice->invoiceStopDateObject();
	}

}
//end of file ActivePlan.php