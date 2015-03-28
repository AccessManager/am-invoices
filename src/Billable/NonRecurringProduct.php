<?php

namespace AccessManager\Invoices\Billable;
use AccessManager\Invoices\Helpers\Database;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class NonRecurringProduct {

	private $account;
	private $product;
	private $invoice;
	private $startDate	= NULL;
	private $stopDate	= NULL;
	private $months 	= 0;
	private $days 		= 0;
	private $amount 	= 0;
	private $tax 		= 0;

	private function _amount()
	{
		return $this->product->price ?: 0;
	}

	private function _tax()
	{
		if( $this->product->taxable ) {
			$this->tax = ( $this->product->price * $this->product->tax_rate ) / 100;
		}
		return $this->tax;
	}

	public function addToInvoice()
	{
		DB::table('ap_invoice_non_recurring_products')
			->insert([
					'invoice_id'		=>		$this->invoice->id(),
					'name'				=>		$this->product->name,
					'amount'			=>		$this->_amount(),
					'tax'				=>		$this->_tax(),
				]);
		DB::table('ap_user_non_recurring_products')
			->where('id', $this->product->id)
			->delete();
	}

	public function __construct( $product, $invoice)
	{
		$this->product = $product;
		$this->invoice = $invoice;

	}

}
//end of file NonRecurringProduct.php