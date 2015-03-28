<?php
namespace AccessManager\Invoices\Billable;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Helpers\Database;
use AccessManager\Invoices\Interfaces\BillableInterface;
use AccessManager\Invoices\Interfaces\ChangedPlanInterface;
use Carbon\Carbon;

class ChangedPlan implements BillableInterface {

	private $plan;
	private $invoice;
	private $startDate 			= NULL;
	private $stopDate 			= NULL;
	private $months 			= 0;
	private $days 				= 0;
	private $costPerDay 		= 0;
	private $amount 			= 0;
	private $tax				= 0;
	private $adjustment 		= 0;
	private $durationCalcuated 	= FALSE;
	private $lastBillActivePlan = NULL;

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
		// if( ! $this->_isChargeable() )		return 0;
		$this->_calculateDuration();
		$this->_calculateCostPerDay();
		$this->_calculateAmount();
		return $this->amount;
	}

	public function tax()
	{
		$this->_calculateTax();
		return $this->tax;
	}

	private function _calculateAmount()
	{
		$this->amount = ($this->days * $this->costPerDay) + ($this->plan->price * $this->months);
	}

	private function _calculateTax()
	{
		if( $this->plan->tax_rate ) {
			$this->_calculateAdjustment();
			$tax = ( ($this->amount - $this->adjustment ) * $this->plan->tax_rate ) / 100;
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
		if( $this->durationCalcuated )		return;

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

		$this->durationCalcuated = TRUE;
	}

	private function _calculateAdjustment()
	{
		if( $this->adjustment )		return;

		// $last_bill_plan = DB::table( 'ap_invoice_plans as p' )
		// 					->join('ap_invoices as i','i.id','=','p.invoice_id')
		// 					->where('i.user_id', $this->invoice->account->user_id)
		// 					->where( 'invoice_id', '<', $this->invoice->id() )
		// 					->orderby( 'p.billed_till','DESC' )
		// 					->select( 'p.billed_from', 'p.billed_till','p.rate' )
		// 					->first();

		if( $this->lastBillActivePlan == NULL )		return;

		$lastInvoiceBilledTill = new Carbon($this->lastBillActivePlan->billed_till);
		
		if( 
			$this->startDate >  $lastInvoiceBilledTill
			|| $this->startDate < (  new Carbon( $this->lastBillActivePlan->billed_from )  )
			)		return;

		$changedPlanStopDate = new Carbon($this->plan->to_date);

		$costPerDay = calculateCostPerDay($this->lastBillActivePlan->rate);

		$gapStartDate = clone $this->startDate;

		$gapStopDate =  $this->stopDate < $lastInvoiceBilledTill ? clone $this->stopDate : clone $lastInvoiceBilledTill;

		$gapStopDate->addDay();
		
		$gap = $gapStartDate->diff($gapStopDate);

		$this->adjustment = ( $this->lastBillActivePlan->rate * $gap->format('%m') + $costPerDay * $gap->format('%d') );

	}

	// private function _isChargeable()
	// {
	// 	if( $this->lastBillActivePlan == NULL )		return TRUE;

	// 	if(  
	// 			( new Carbon( $this->lastBillActivePlan->billed_till )  ) > ( new Carbon( $this->plan->to_date )  )

	// 		)	return FALSE;

	// 	return TRUE;
	// }

	public function addToInvoice()
	{
		if($this->stopDate < $this->startDate)	return;

		$this->_calculateAdjustment();

		echo "<br/> Adding Plan to Invoice.",
		'Plan Name:', $this->plan->plan_name,
		'Start Date:', $this->startDate(),
		'Stop Date', $this->stopDate(),'<br />';


		DB::table('ap_invoice_plans')
			->insert([
						'invoice_id'	=>	$this->invoice->id(),
						'plan_name'		=>	$this->plan->plan_name,
						'billed_from'	=>	$this->startDate(),
						'billed_till'	=>	$this->stopDate(),
						'amount'		=>	$this->amount(),
						'tax'			=>	$this->tax(),
						'rate'			=>	$this->plan->price,
						'adjustment'	=> 	$this->adjustment,
						'tax_rate'		=>	$this->plan->tax_rate,
				]);
		DB::table('ap_change_history')
			->where('id', $this->plan->id)
			->delete();
	}

	public function __construct( $changedPlan, $invoice, $lastBillActivePlan )
	{
		Database::connect();

		$this->plan 				= $changedPlan;
		$this->invoice 				= $invoice;
		$this->lastBillActivePlan 	= $lastBillActivePlan;
		$this->_makeStartStopDates();
	}

	private function _makeStartStopDates()
	{
		$this->startDate = new Carbon(date('Y-m-d',strtotime($this->plan->from_date)));
		$this->stopDate = (new Carbon(date('Y-m-d', strtotime($this->plan->to_date))))->subDay();
	}

}
//end of file ChangedPlan.php