<?php
namespace Shipper;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

class NcsaCommand extends Command
{
    protected $config;

    public function __construct()
    {
        parent::__construct();

    }

    protected function configure()
    {
        $this
            ->setName('ncsa')
            ->setDescription('Run varnishncsa dumping logs based on config.php')
            ->addOption('config', null, InputArgument::OPTIONAL, "Config file")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $input->getOption('config');
        if (empty($config))
            $config = $this->defaultConfig();
        if (!file_exists($config))
        {
            throw new Exception("Can't load config file: $config");
        }

        $conf = include($config);

        $shipper = new Shipper($conf);
        $shipper->ship('Varnishncsa');
    }

    protected function defaultConfig()
    {
        return realpath(__DIR__.'/../../config.php');
    }
}
