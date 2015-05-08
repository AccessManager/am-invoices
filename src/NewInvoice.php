<?php
namespace AccessManager\Invoices;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Helpers\Database;
use AccessManager\Invoices\Billable\NewInvoicePlan;
use AccessManager\Invoices\Billable\ActivePlan;
use AccessManager\Invoices\Billable\ChangedPlan;
use AccessManager\Invoices\Billable\RecurringProduct;
use AccessManager\Invoices\Billable\NonRecurringProduct;
use Carbon\Carbon;
use Exception;

class NewInvoice {

	private $account;
	private $invoiceId 				= FALSE;
	private $startDate 				= NULL;
	private $stopDate 				= NULL;
	private $activePlan 			= NULL;
	private $changedPlans 			= [];
	private $billablePlans 			= [];
	private $billableProducts 		= [];

	private function _fetchActivePlan()
	{
		$activePlan = DB::table('ap_active_plans as ap')
									->where('ap.user_id',$this->account->user_id)
									->select('ap.assigned_on','ap.plan_name','ap.price')
									->first();
		if ( $activePlan == NULL )
			throw new Exception("No Active Plan Found.");

		$this->activePlan = $activePlan;
	}

	private function _fetchChangedPlans()
	{
		$q = DB::table('ap_change_history as h')
				->where('h.user_id',$this->account->user_id)
				->orderby('h.from_date','ASC')
				->select('h.from_date','h.to_date','h.plan_name','h.price');
		if( $this->account->billed_till != NULL ) {
			$q->where('h.to_date','>',$this->account->billed_till);
		}

		$plans = $q->get();
 
		if( $plans != NULL )
			$this->changedPlans = $plans;
	}

	private function _fetchProducts()
	{
		$this->_fetchRecurringProducts();
		$this->_fetchNonRecurringProducts();
	}

	private function _fetchRecurringProducts()
	{
		$q = DB::table('ap_user_products as p')
						->where('is_recurring', RECURRING)
						->where('user_id', $this->account->user_id)
						->select('p.name','p.assigned_on','p.expiry','p.is_recurring'
								,'p.price','p.taxable','p.tax_rate');

		if( isValidDate($this->account->last_billed_on)) {
			$q->where('expiry','>',$this->account->last_billed_on);
		}

		$products = $q->get();
		
		if ( $products != NULL )
			foreach($products as $product )
				$this->billableProducts[] = new RecurringProduct($product, $this);
	}

	private function _fetchNonRecurringProducts()
	{
		$q = DB::table('ap_user_products as p')
					->where('is_recurring', NON_RECURRING)
					->where('user_id', $this->account->user_id)
					->select('p.name','p.assigned_on','p.expiry','p.is_recurring'
							,'p.price','p.taxable','p.tax_rate');

		if( isValidDate($this->account->last_billed_on) ) {
			$q->where('assigned_on', '>', $this->account->last_billed_on );
		}
		$products = $q->get();

		if ( $products != NULL )
			foreach($products as $product )
				$this->billableProducts[] = new NonRecurringProduct($product, $this);
	}

	public function addPlans()
	{
		$this->_fetchActivePlan();
		$this->billablePlans[] = new ActivePlan($this->activePlan, $this);
		$assignedOn = new Carbon($this->activePlan->assigned_on);
		$lastBilledTill = new Carbon($this->account->billed_till);
		if( $assignedOn > $lastBilledTill ) {
			$this->_fetchChangedPlans();
			foreach($this->changedPlans as $plan)
				$this->billablePlans[] = new ChangedPlan( $plan, $this );
		}
		foreach($this->billablePlans as $plan) {
			$plan->addToInvoice();
		}
	}

	public function addProducts()
	{
		$this->_fetchProducts();

		foreach( $this->billableProducts as $product ) {
			$product->addToInvoice();
		}
	}

	public function finalize()
	{
		DB::table('billing_cycles')
			->where('user_id', $this->account->user_id)
			->update([
					'last_billed_on'	=>		date('Y-m-d H:i:s'),
					'billed_till'		=>		$this->invoiceStopDate(),
				]);
		$cr_row = DB::table('ap_transactions as t')
				->where('user_id', $this->account->user_id)
				->where('type','cr')
				->select(DB::raw("sum(amount) as amount"))
				->first();
		$credited = $cr_row->amount;

		$dr_row = DB::table('ap_transactions as t')
				->where('user_id', $this->account->user_id)
				->where('type','dr')
				->select(DB::raw("sum(amount) as amount"))
				->first();
		$debited = $dr_row->amount;
		$balance = $debited - $credited;

		DB::table('ap_invoices')
			->where('id',$this->invoiceId)
			->update(['prev_adjustments'=>$balance]);
		$this->_addToTransactions();
	}

	private function _addToTransactions()
	{
		$row = DB::table('ap_invoices as i')
				->leftJoin('ap_invoice_plans as p','i.id','=','p.invoice_id')
				->where('i.id', $this->invoiceId)
				->select(DB::raw('sum(amount) as amount'))
				->first();
				
		DB::table('ap_transactions')
			->insert([
						'user_id'	=>	$this->account->user_id,
						 'amount'	=>	$row->amount,
						   'type'	=>	'dr',
					 'created_at'	=>	date('Y-m-d H:i:s'),
					'description'	=>	'invoice generated',
				]);
	}

	public function id()
	{
		return $this->invoiceId;
	}

	private function _generateBlankInvoice()
	{
		$this->invoiceId = DB::table('ap_invoices')
								->insertGetId([
										      	 
										  'invoice_number'	=>		$this->_makeInvoiceNumber(),
										  		'user_id'	=>		$this->account->user_id,
										  		'org_id'	=>		$this->account->org_id,
											'generated_on'	=>		date('Y-m-d h:i:s'),
									   'bill_period_start'	=>		$this->invoiceStartDate(),
									    'bill_period_stop'	=>		$this->invoiceStopDate(),
									]);
	}

	public function invoiceStartDate()
	{
		$this->_makeInvoiceStartDateObject();
		return $this->startDate->format('Y-m-d');
	}

	public function invoiceStartDateObject()
	{
		$this->_makeInvoiceStartDateObject();
		return $this->startDate;
	}

	private function _makeInvoiceStartDateObject()
	{
		$startDate = ( $this->account->billed_till == NULL 
						|| $this->account->billed_till == '0000-00-00 00:00:00') ?
			  $this->_makeInvoiceStartDate() : date( 'Y-m-d', strtotime('+1 Day',strtotime($this->account->billed_till)) );
		 $this->startDate = new Carbon(date('Y-m-d',strtotime($startDate)));
	}

	private function _makeInvoiceStartDate()
	{
		$this->_fetchChangedPlans();

		if( count($this->changedPlans) )
		 return $this->changedPlans[0]->from_date;
		return $this->activePlan->assigned_on;
	}

	public function invoiceStopDate()
	{
		$this->_makeInvoiceStopDateObject();
		return $this->stopDate->format('Y-m-d');
	}

	public function invoiceStopDateObject()
	{
		$this->_makeInvoiceStopDateObject();
		return $this->stopDate;
	}

	private function _makeInvoiceStopDateObject()
	{
		$exp = $this->account->expiration;

		$stopDateTimestamp = ( $exp == '0000-00-00 00:00:00' || $exp == NULL || ! $this->_expiringSoon($exp) ) ?
									$this->_makeCycleEndStamp() :
									strtotime($exp);
		$this->stopDate = Carbon::createFromTimestamp($stopDateTimestamp);
	}

	private function _expiringSoon( $expiration )
	{
		$exp_date_stamp = strtotime( date('Y-m-d',strtotime($expiration)) );
		return $exp_date_stamp < $this->_makeCycleEndStamp();
	}

	private function _makeCycleEndStamp()
	{
		switch( $this->account->bill_duration_type ) {
			case BILL_DURATION_CYCLE :
			$date = new Carbon($this->invoiceStartDate());
			break;
			case BILL_DURATION_FULL :
			$date = new Carbon( date('Y-m-d') );
			break;
		}
		$date->modify("+{$this->account->billing_cycle} {$this->account->billing_unit}");
		 $date->modify('-1 Day');
		 return $date->getTimeStamp();
	}

	private function _makeInvoiceNumber()
	{
		$thisMonth = date('Ym');
		
		$lastInvoice = DB::table('ap_invoices as i')
							->where('i.org_id', $this->account->org_id)
							->where('i.invoice_number','LIKE',"$thisMonth%")
							->orderBy('i.invoice_number','DESC') 
							->select('i.invoice_number')
							->first();

		if( $lastInvoice == NULL )
			return $thisMonth . 1;

		$lastNumber = substr($lastInvoice->invoice_number, 6);
		return $thisMonth .= ++$lastNumber;
	}

	public function __construct($account)
	{
		$this->account = $account;
		Database::connect();
		$this->_fetchActivePlan();
		$this->_generateBlankInvoice();
	}

}
//end of file Invoice.php