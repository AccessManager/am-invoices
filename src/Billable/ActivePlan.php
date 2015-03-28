<?php
namespace AccessManager\Invoices\Billable;
use AccessManager\Invoices\Interfaces\BillableInterface;
use AccessManager\Invoices\Helpers\Database;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class ActivePlan implements BillableInterface {

	private $plan;
	private $invoice;
	private $startDate  				= NULL;
	private $stopDate 					= NULL;
	private $months 					= 0;
	private $days 						= 0;
	private $costPerDay 				= 0;
	private $amount 					= 0;
	private $tax 						= 0;
	private $adjustment 				= 0;
	private $durationCalculated 		= FALSE;
	private $apSettings 				= NULL;
	private $lastBillActivePlan 		= NULL;

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
		// $this->_calculateTax();
		return $this->amount;
	}

	public function tax()
	{
		$this->_calculateTax();
		return $this->tax;
	}

	private function _calculateAdjustment()
	{
		if( $this->adjustment )		return;

		if( $this->lastBillActivePlan == NULL ) 	return;

		$activePlanAssignedOn = new Carbon( date('Y-m-d', strtotime($this->plan->assigned_on)) );
		$lastBillActivePlanAssignedOn = new Carbon(date('Y-m-d', strtotime($this->lastBillActivePlan->billed_from)) );

		if( $activePlanAssignedOn <= $lastBillActivePlanAssignedOn ) 	return;

		$lastBillActivePlanBilledTill = new Carbon( date('Y-m-d', strtotime($this->lastBillActivePlan->billed_till)) );

		if( $lastBillActivePlanBilledTill < $activePlanAssignedOn ) 	return;

		$costPerDay = calculateCostPerDay( $this->lastBillActivePlan->rate );

		$lastBillActivePlanBilledTill->addDay();
		
		$diff = $activePlanAssignedOn->diff( $lastBillActivePlanBilledTill );

		$this->adjustment = ($this->lastBillActivePlan->rate * $diff->format('%m') + $diff->format('%d') * $costPerDay );
	}

	private function _calculateAmount()
	{
		$this->_calculateDuration();
		$this->_calculateCostPerDay();
		
		$this->amount = ($this->days * $this->costPerDay) + ($this->plan->price * $this->months);
	}

	private function _calculateTax()
	{
		$this->_fetchAPSettings();

		if( $this->apSettings->plan_taxable ) {
			$this->_calculateAdjustment();
			$tax = ( ($this->amount - $this->adjustment ) * $this->apSettings->plan_tax_rate ) / 100;
			$this->tax = round($tax, 2);
		}
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
				
				if ($stopDate->format('m') <= $this->startDate->format('m') )		
					break;

				$stopDate->subMonth();
				$this->months++;
			}
		}
		$diff = $this->startDate->diff($stopDate);
		$this->days = $diff->format('%d');

		$this->durationCalculated = TRUE;
	}

	public function addToInvoice()
	{
		if($this->stopDate < $this->startDate)	return;
		$this->_calculateAdjustment();

		DB::table('ap_invoice_plans')
			->insert([
						'invoice_id'	=>	$this->invoice->id(),
						'plan_name'		=>	$this->plan->plan_name,
						'billed_from'	=>	$this->startDate(),
						'billed_till'	=>	$this->stopDate(),
						'amount'		=>	$this->amount(),
						'tax'			=>	$this->tax(),
						'rate'			=>	$this->plan->price,
						'adjustment'	=>  $this->adjustment,
						'tax_rate'		=>	$this->apSettings->plan_tax_rate,
				]);
	}

	public function __construct( $activePlan, $invoice, $lastBillActivePlan )
	{
		Database::connect();
		
		$this->plan 				= $activePlan;
		$this->invoice 				= $invoice;
		$this->lastBillActivePlan 	= $lastBillActivePlan;
		$this->_makeStartStopDates();
		$this->_fetchAPSettings();
	}

	private function _makeStartStopDates()
	{
		$lastInvoice 		= $this->_fetchLastInvoice();
		$invoiceStartDate 	= $this->invoice->invoiceStartDateObject();
		$planStartDate 		= new Carbon(date('Y-m-d', strtotime($this->plan->assigned_on)));

		if( is_null($lastInvoice) ) {
			$this->startDate = $planStartDate < $invoiceStartDate ? $planStartDate : $invoiceStartDate;
			// if( $planStartDate < $invoiceStartDate ) {
			// 	$this->startDate = $planStartDate;
			// } else {
			// 	$this->startDate = $invoiceStartDate;
			// }
		} else {
			$lastInvoiceStartDate = new Carbon( $lastInvoice->bill_period_start );
			$planStartDate = new Carbon(date('Y-m-d', strtotime($this->plan->assigned_on)));
			if( $planStartDate < $lastInvoiceStartDate ) {
				$this->startDate = $invoiceStartDate;
			} else {
				$this->startDate = $planStartDate < $invoiceStartDate ? $planStartDate : $invoiceStartDate;
				// if( $planStartDate < $invoiceStartDate ) {
				// 	$this->startDate = $planStartDate;
				// } else {
				// 	$this->startDate = $invoiceStartDate;
				// }
			}
		}
		// $this->startDate = $planStartDate < $invoiceStartDate ? $invoiceStartDate : $planStartDate;
		$this->stopDate = $this->invoice->invoiceStopDateObject();
	}

	private function _fetchLastInvoice()
	{
		$invoice = DB::table('ap_invoices as i')
						->where('user_id', $this->invoice->account->user_id)
						->orderby('id', 'DESC')
						->skip(1)
						->select('i.bill_period_start')
						->first();
		echo "Last Invoice:";
		print_r($invoice);
		return $invoice;
	}

	private function _fetchAPSettings()
	{
		if( $this->apSettings == NULL )
			$this->apSettings = DB::table('ap_settings as s')
									->select('s.plan_taxable','s.plan_tax_rate')
									->first();
	}

}
//end of file ActivePlan.php