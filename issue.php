#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Symfony\Component\Console\Application;
use DrupalIssueHelper\Command\InteractiveCommand;
use DrupalIssueHelper\Command\UpdateIssuesCommand;
use DrupalIssueHelper\Command\GiveSuggestionsCommand;
use DrupalIssueHelper\Command\CheckIssuesCommand;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$application = new Application('Drupal Issue Hygiene Helper', '1.0.0');

$application->add(new InteractiveCommand());
$application->add(new UpdateIssuesCommand());
$application->add(new GiveSuggestionsCommand());
$application->add(new CheckIssuesCommand());

// Set interactive as the default command when no command is specified
$application->setDefaultCommand('interactive');

$application->run();
