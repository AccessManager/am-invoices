<?php
namespace AccessManager\Invoices\Interfaces;

Interface BillableInterface {

	public function startDate();

	public function stopDate();

	public function amount();

	public function addToInvoice();
}
//end of file Billable.php