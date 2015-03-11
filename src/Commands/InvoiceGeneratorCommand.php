<?php
namespace AccessManager\Invoices\Commands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AccessManager\Invoices\InvoiceGenerator;

class InvoiceGeneratorCommand extends Command {

	protected function configure()
	{
		$this->setName('cron:ap:generateInvoices');
	}

	protected function execute( InputInterface $input, OutputInterface $output )
	{
		InvoiceGenerator::generateInvoices();
	}
}
// end of file InvoiceGeneratorCommand.php