<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use Drupal\migrate\MigrateMessageInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the migrate executable's messages on the Drush console.
 *
 * Errors and warnings show as such; status messages (e.g. "Processed N
 * items") in plain text, the command prints its own summary after the run.
 */
final class IoMigrateMessage implements MigrateMessageInterface {

  public function __construct(private readonly SymfonyStyle $io) {}

  /**
   * {@inheritdoc}
   */
  public function display($message, $type = 'status') {
    $text = (string) $message;
    match ($type) {
      'error' => $this->io->error($text),
      'warning' => $this->io->warning($text),
      default => $this->io->writeln('  ' . $text),
    };
  }

}
