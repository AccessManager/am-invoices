<?php
namespace AccessManager\Invoices\Billable;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Interfaces\BillableInterface;
use AccessManager\Invoices\Interfaces\ChangedPlanInterface;
use Carbon\Carbon;

class ChangedPlan implements BillableInterface, ChangedPlanInterface {

	private $plan;
	private $invoice;
	private $startDate = NULL;
	private $stopDate = NULL;
	private $months = 0;
	private $days = 0;
	private $costPerDay = 0;
	private $amount = 0;

	public function startDate()
	{
		return $this->startDate->format('Y-m-d');
	}

	public function stopDate()
	{
		return $this->stopDate->format('Y-m-d');
	}
	public function amount()
	{
		$this->_calculateDuration();
		$this->_calculateCostPerDay();
		$this->_calculateAmount();
		return $this->amount;
	}

	private function _calculateAmount()
	{
		$this->amount = ($this->days * $this->costPerDay) + ($this->plan->price * $this->months);
	}

	private function _calculateCostPerDay()
	{
		$cost_per_day = $this->plan->price / 30;
		$this->costPerDay = number_format( (float) $cost_per_day,2,'.','');
	}

	private function _calculateDuration()
	{
		$stopDate = clone $this->stopDate;
		$stopDate->addDay();
		// print_r($this->startDate);
		// print_r($stopDate);
		while( $stopDate > $this->startDate ) {
			
			if ($stopDate->format('m') <= $this->startDate->format('m') )		
				break;

			$stopDate->subMonth();
			$this->months++;
		}
		$diff = $this->startDate->diff($stopDate);
		// print_r($diff);
		$this->days = $diff->format('%d');
		echo $this->months . "Months " . $this->days . "Days. <br />";
	}

	public function adjustedAmount()
	{
		
	}

	public function addToInvoice()
	{
		echo $this->plan->plan_name, ' ', var_dump($this->amount()), '<br />';
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

	public function __construct( $changedPlan, $invoice )
	{
		$this->plan = $changedPlan;
		$this->invoice = $invoice;
		$this->_makeStartStopDates();
		Database::connect();
	}

	private function _makeStartStopDates()
	{
		$this->startDate = new Carbon(date('Y-m-d',strtotime($this->plan->from_date)));
		$this->stopDate = (new Carbon(date('Y-m-d', strtotime($this->plan->to_date))))->subDay();
	}

}
//end of file ChangedPlan.php