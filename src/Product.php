<?php
namespace AccessManager\Invoices;
use Illuminate\Database\Capsule\Manager as DB;
use AccessManager\Invoices\Helpers\Database;
use AccessManager\Invoices\billable\NonRecurringProduct;
use AccessManager\Invoices\billable\RecurringProduct;
use AccessManager\Invoices\Billable\ChangedRecurringProduct;
use Carbon\Carbon;

class Product {

	private $account;
	private $invoice;
	private $recurringProducts 		= [];
	private $billableProducts 		= [];

	public static function getInvoiceableProducts( $account, $invoice )
	{
		$obj = new static( $account, $invoice );
		return $obj->fetchRecurringProducts()
					->fetchNonRecurringProducts()
					->fetchChangedRecurringProducts()
					->getBillableProducts();
	}

	public function getBillableProducts()
	{
		return $this->billableProducts;
	}

	public function fetchNonRecurringProducts()
	{
		$products = DB::table('ap_user_non_recurring_products as p')
						->where('user_id', $this->account->user_id)
						->select('p.id','p.name','p.assigned_on','p.price','p.taxable','p.tax_rate')
						->get();

		if( $products != NULL )
			foreach( $products as $product )
				$this->billableProducts[] = new NonRecurringProduct( $product, $this->invoice );

		return $this;
	}

	public function fetchRecurringProducts()
	{
		// DB::enableQueryLog();
		$q = DB::table('ap_user_recurring_products as p')
										->where('user_id', $this->account->user_id)
										->where(function($query){
											$query->where(function($query){
												$query->where('p.billed_till','<','p.expiry')
														->orWhere('p.billed_till','0000-00-00 00:00:00')
														->orWhere('p.expiry','0000-00-00 00:00:00');
											});
										})
										->select('p.id','p.name','p.assigned_on','p.billed_till','p.price',
											'p.taxable','p.tax_rate','p.expiry','p.billing_cycle','p.billing_unit',
											'p.last_billed_on');
		// if( isValidDate($this->account->last_billed_on) ) {
		// 	$q->where('billed_till','<',$this->account->last_billed_on);
		// }

		$this->recurringProducts = $q->get();

		// echo __FILE__ . __LINE__;
		// print_r($this->recurringProducts);

		$this->_filterRecurringProducts();
		return $this;
	}

	public function fetchChangedRecurringProducts()
	{
		$changedProducts = DB::table('ap_user_recurring_products_history as p')
								->where('p.user_id', $this->account->user_id)
								->select('p.id','p.name','p.start_date','p.stop_date','p.price','p.taxable',
									'p.tax_rate','p.billed_every')
								->get();

		if( $changedProducts != NULL )
			foreach( $changedProducts as $product )
				$this->billableProducts[] = new ChangedRecurringProduct( $product, $this->invoice );
		return $this;
	}

	private function _filterRecurringProducts()
	{
		foreach( $this->recurringProducts as $product ) {
			// var_dump($product);
			if( ! isValidDate($product->last_billed_on) ||
					( $this->_billingCycleCompleted( $product ) && ! $this->_generatedThisMonth($product))
				) {
				// var_dump($product);
				$this->billableProducts[] = new RecurringProduct( $product, $this->invoice );
			}
		}
	}

	public function __construct($account, $invoice )
	{
		$this->account = $account;
		$this->invoice = $invoice;
		Database::connect();
	}

	private function _generatedThisMonth( $product )
	{
		$last_billed_on = new Carbon($product->last_billed_on);
		$this_month = new Carbon;
		$generated = $last_billed_on->format('m') == $this_month->format('m');
		 // var_dump($generated);
		 return $generated;
	}

	private function _billingCycleCompleted( $product )
	{
		$next_bill_cycle = ( new Carbon($product->last_billed_on) )
								->modify("+ $product->billing_cycle $product->billing_unit");
		 return $next_bill_cycle->getTimestamp() <= time();
	}

}
//end of file Product.php