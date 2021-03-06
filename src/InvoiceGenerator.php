<?php
namespace AccessManager\Invoices;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Helpers\Database;
use Carbon\Carbon;
use DateTime;

class InvoiceGenerator {

	private $activeAccounts;
	private $invoiceableAccounts = [];


	public static function generateInvoices()
	{
		$generator = new self;
		return $generator->fetchActiveAccounts()
							->filterBillableAccounts()
							->generate();
	}

	public function fetchActiveAccounts()
	{
		$q = DB::table('billing_cycles as bc')
				->join( 'ap_active_plans as ap','ap.user_id','=','bc.user_id' )
				->join( 'user_accounts as u','u.id','=','bc.user_id' )
				->where( 'bc.bill_date',date('d') )
				->where( 'u.status','!=', TERMINATED )
				->where( 'u.plan_type', ADVANCEPAID_PLAN )
				->where(function($query){
					$query
							->where(function($query){
								$query->where('bc.billed_till','<','bc.expiration')
									->orWhere('bc.billed_till', NULL);
							})
							->orWhere('bc.expiration','0000-00-00 00:00:00');
				})
				->select('bc.billing_cycle','bc.billing_unit','bc.org_id','bc.last_billed_on',
					'bc.billed_till','bc.expiration','bc.bill_duration_type'
					,'ap.assigned_on','ap.user_id','u.uname');

		$this->activeAccounts = $q->get();
		return $this;
	}

	public function filterBillableAccounts()
	{
		foreach($this->activeAccounts as $account ) {
			if( is_null($account->last_billed_on) 
					|| ( $this->_billingCycleCompleted($account) && ! $this->_generatedThisMonth($account) )
				) {

				if( ! $this->_isNewAccount( $account ) ) {
					$this->invoiceableAccounts[] = $account;
				}
			}
		}
		return $this;
	}

	public function generate()
	{
		$invoices = [];
		if( count( $this->invoiceableAccounts) == 0 )	exit("No accounts to generate invoices for.");
		
		foreach($this->invoiceableAccounts as $account ) {
			DB::transaction(function()use($account){
				$newInvoice = new NewInvoice( $account );
				$newInvoice->addPlans();
				$newInvoice->addProducts();
				$newInvoice->finalize();
			});
		}
	}

	private function _generatedThisMonth( $account )
	{
		$last_generated_on_timestamp = strtotime(date('Y-m', strtotime($account->last_billed_on)));
		$this_month = strtotime(date('Y-m'));
		return $last_generated_on_timestamp == $this_month;
	}

	private function _isNewAccount( $account )
	{
		$activePlanAssignedOn = strtotime( $account->assigned_on );
		
		if( $activePlanAssignedOn < time() )	return FALSE;

		$changedPlans = DB::table('ap_change_history as ch')
							->where('user_id', $account->user_id)
							->get();
							
		return (count($changedPlans) > 0 ) ? FALSE : TRUE;	
		// if ( count($changedPlans) > 0 ) {
		// 	return FALSE;
		// }  else {
		// 	return TRUE;
		// }
	}

	private function _billingCycleCompleted($account)
	{
		$assigned_on = strtotime($account->assigned_on);
		$d = new DateTime($account->last_billed_on);
		$d->modify("+{$account->billing_cycle} {$account->billing_unit}");
		$bill_date = $d->getTimestamp();
		return $bill_date <= time();
	}

	public function __construct()
	{
		Database::connect();
	}
}
//end of file Invoice.php