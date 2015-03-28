<?php
namespace AccessManager\Invoices\Billable;
use AccessManager\Invoices\Helpers\Database;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class RecurringProduct {

	private $product;
	private $invoice;
	private $startDate 			= NULL;
	private $stopDate			= NULL;
	private $costPerDay 		= 0;
	private $months 			= 0;
	private $days 				= 0;
	private $amount 			= 0;
	private $tax 				= 0;
	private $durationCalculated = FALSE;

	private function _startDate()
	{
		return $this->startDate->format('Y-m-d');
	}

	private function _stopDate()
	{
		return $this->stopDate->format('Y-m-d');
	}

	private function _amount()
	{
		$this->_calculateDuration();
		$this->_calculateCostPerDay();
		$this->_calculateAmount();
		return $this->amount;
	}

	private function _tax()
	{
		$amount = $this->_amount();
		if( $this->product->taxable ){
			$this->tax = ( $amount * $this->product->tax_rate ) / 100;
		}
		return $this->tax;
	}

	private function _calculateAmount()
	{
		$this->amount = ($this->days * $this->costPerDay) + ($this->product->price * $this->months);
	}

	private function _calculateCostPerDay()
	{
		$cost_per_day = $this->product->price / 30;
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

		$this->durationCalculated = TRUE;
	}

	public function addToInvoice()
	{
		DB::table('ap_invoice_recurring_products')
			->insert([
					'invoice_id'	=>		$this->invoice->id(),
					'name'			=>		$this->product->name,
					'billed_from'	=>		$this->_startDate(),
					'billed_till'	=>		$this->_stopDate(),
					'amount'		=>		$this->_amount(),
					'tax'			=>		$this->_tax(),
					'rate'			=>		$this->product->price,
				]);
		DB::table('ap_user_recurring_products')
			->where('id',$this->product->id)
			->update([
				'last_billed_on'	=> 	date('Y-m-d H:i:s'),
				'billed_till'		=>	$this->_stopDate(),
				]);
	}

	public function __construct( $product, $invoice )
	{
		Database::connect();
		$this->product = $product;
		$this->invoice = $invoice;
		$this->_makeStartStopDates();
	}

	private function _makeStartStopDates()
	{
		if( ! isValidDate($this->product->last_billed_on) ) {
			$invoiceStartDate = $this->invoice->invoiceStartDateObject();
			$productStartDate = new Carbon( date('Y-m-d', strtotime($this->product->assigned_on)) );
			$this->startDate = $productStartDate < $invoiceStartDate ? $invoiceStartDate : $productStartDate;			
		} else {
			$this->startDate = ( new Carbon( date('Y-m-d', strtotime($this->product->billed_till)) ) )->addDay();
		}
		
		if( ! isValidDate($this->product->expiry) )
			return $this->stopDate = $this->invoice->invoiceStopDateObject();

		$invoiceStopDate = $this->invoice->invoiceStopDateObject();
		$productStopDate = new Carbon(date('Y-m-d', strtotime($this->product->expiry)));
		$this->stopDate = $productStopDate < $invoiceStopDate ? $productStopDate : $invoiceStopDate;
	}

}
//end of file BaseProduct.php