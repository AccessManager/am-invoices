<?php
namespace AccessManager\Invoices\Billable;
use AccessManager\Invoices\Helpers\Database;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class ChangedRecurringProduct {


	private $product 				= NULL;
	private $Invoice 				= NULL;
	private $startDate;
	private $stopDate;
	private $durationCalculatd 		= FALSE;
	private $fullBillingDuration	= 0;
	private $days					= 0;
	private $amount 				= 0;
	private $tax 					= 0;
	private $durationCalculated		= 0;

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

	private function _calculateCostPerDay()
	{
		$today = new Carbon;
		$after_billing_cycle = (new Carbon)->modify("+ {$this->product->billed_every}");
		$diff = $today->diff($after_billing_cycle);
		$days_in_cycle = $diff->format('%a');
		$cost_per_day = $this->product->price / $days_in_cycle;
		$this->costPerDay = number_format( (float) $cost_per_day,2,'.','');
	}

	private function _calculateAmount()
	{
		$this->amount = ($this->days * $this->costPerDay) + ($this->product->price * $this->fullBillingDuration);
		return $this->amount;
	}

	private function _tax()
	{
		$this->_calculateAmount();
		$this->_calculateTax();
		return $this->tax;
	}

	private function _calculateTax()
	{
		if( $this->product->taxable ){
			$this->tax = ( $this->amount * $this->product->tax_rate ) / 100;
		}
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
				$stopDate->modify("- {$this->product->billed_every}");
				$this->fullBillingDuration++;
			}
		}
		$diff = $this->startDate->diff($stopDate);
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

		DB::table('ap_user_recurring_products_history')
			->where('id', $this->product->id)
			->delete();
	}

	public function __construct( $product, $invoice )
	{
		$this->product = $product;
		$this->invoice = $invoice;
		$this->startDate = new Carbon(date('Y-m-d', strtotime($this->product->start_date)));
		$this->stopDate = ( new Carbon(date('Y-m-d', strtotime($this->product->stop_date))) )->subDay();
		Database::connect();
	}

}
//end of file ChangedRecurringProduct.php