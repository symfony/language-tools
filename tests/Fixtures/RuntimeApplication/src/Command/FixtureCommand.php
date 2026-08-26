<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fixture:console')]
final class FixtureCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::OPTIONAL)
            ->addOption('format', null, InputOption::VALUE_REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $input->getArgument('message');
        $input->getOption('format');
        $input->getOption('help');
        $input->getOption('verbose');
        $input->getOption('env');
        $input->getOption('no-debug');

        return self::SUCCESS;
    }
}
