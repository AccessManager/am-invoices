<?php
namespace AccessManager\Invoices;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Helpers\Database;
use AccessManager\Invoices\Billable\ActivePlan;
use AccessManager\Invoices\Billable\ChangedPlan;
use AccessManager\Invoices\Billable\RecurringProduct;
use AccessManager\Invoices\Billable\NonRecurringProduct;
use AccessManager\Invoices\Billable\ChangedRecurringProduct;
use Carbon\Carbon;
use Exception;

class NewInvoice {

	public $account;
	private $invoiceId 				= FALSE;
	private $startDate 				= NULL;
	private $stopDate 				= NULL;
	private $activePlan 			= NULL;
	private $changedPlans 			= [];
	private $billablePlans 			= [];

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
		if( empty($this->changedPlans) ) {
			$q = DB::table('ap_change_history as h')
					->where('h.user_id',$this->account->user_id)
					->orderby('h.from_date','ASC')
					->select('h.id','h.from_date','h.to_date','h.plan_name','h.price','h.tax_rate');
			if( $this->account->last_billed_on != NULL ) {
				$q->where('h.from_date','>',$this->account->last_billed_on);
			}
			$plans = $q->get();
			echo "Fetched Changed Plans.";
	 		print_r($plans);
			if( $plans != NULL )
				$this->changedPlans = $plans;
		}
	}

	private function _fetchLastInvoiceActivePlan()
	{
		$lastBillActivePlan = DB::table( 'ap_invoice_plans as p' )
								->join('ap_invoices as i','i.id','=','p.invoice_id')
								->where('i.user_id', $this->account->user_id)
								->where( 'invoice_id', '<', $this->invoiceId )
								->orderby( 'p.billed_till','DESC' )
								->select( 'p.billed_from', 'p.billed_till','p.rate' )
								->first();

		return $lastBillActivePlan;
	}

	public function addPlans()
	{
		$this->_fetchActivePlan();
		$lastBillActivePlan = $this->_fetchLastInvoiceActivePlan();

		$this->billablePlans[] = new ActivePlan($this->activePlan, $this, $lastBillActivePlan );
		$assignedOn = new Carbon($this->activePlan->assigned_on);
		$lastBilledOn = new Carbon($this->account->last_billed_on);
		if( $assignedOn > $lastBilledOn ) {
			$this->_fetchChangedPlans();
			foreach($this->changedPlans as $plan)
				$this->billablePlans[] = new ChangedPlan( $plan, $this, $lastBillActivePlan );
		}
		foreach($this->billablePlans as $plan) {
			$plan->addToInvoice();
		}
	}

	public function addProducts()
	{
		$products = Product::getInvoiceableProducts( $this->account, $this );
		foreach( $products as $product )
			$product->addToInvoice();
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
					// ->skip(1)
					->orderby('id','DESC')
					->select(DB::raw("sum(amount) as amount"))
					->first();
		// $credited = $cr_row->amount;

		$dr_row = DB::table('ap_transactions as t')
					->where('user_id', $this->account->user_id)
					->where('type','dr')
					->orderby('id','DESC')
					// ->skip(1)
					->select(DB::raw("sum(amount) as amount"))
					->first();
		
		$debited 	= $dr_row == NULL ? 0 : $dr_row->amount;
		$credited 	= $cr_row == NULL ? 0 : $cr_row->amount;
		$balance 	= $debited - $credited;
		
		DB::table('ap_invoices')
				->where('id', $this->id())
				->update([
					'prev_adjustments'		=>		$balance,
					]);
		
		$this->_addToTransactions();
	}

	private function _addToTransactions()
	{
		$row = DB::table('ap_invoices as i')
				->join('ap_invoice_plans as p','i.id','=','p.invoice_id')
				->leftJoin('ap_invoice_recurring_products as rp','i.id','=','rp.invoice_id')
				->leftJoin('ap_invoice_non_recurring_products as nrp','i.id','=','nrp.invoice_id')
				->where('i.id', $this->invoiceId)
				->select(
					DB::raw('sum(p.amount) as p_amount'), DB::raw('sum(p.tax) as p_tax'),
					DB::raw('sum(rp.amount) as rp_amount'), DB::raw('sum(rp.tax) as rp_tax'),
					DB::raw('sum(nrp.amount) as nrp_amount'), DB::raw('sum(nrp.tax) as nrp_tax'),
					'i.invoice_number'
					)
				->first();
				
		DB::table('ap_transactions')
			->insert([
		'user_id'	=>	$this->account->user_id,
		 'amount'	=>	($row->p_amount + $row->p_tax + $row->rp_amount + $row->rp_tax + $row->nrp_amount + $row->nrp_tax),
		   'type'	=>	'dr',
	 'created_at'	=>	date('Y-m-d H:i:s'),
	'description'	=>	"invoice generated.({$row->invoice_number})",
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
											'generated_on'	=>		date('Y-m-d H:i:s'),
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
		$startDate = ( ! isValidDate($this->account->billed_till) ) ?
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