<?php

declare(strict_types=1);

namespace MroongaSearch;

use Doctrine\DBAL\Connection;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * Module bootstrap for MroongaSearch.
 *
 * - On install: verifies Mroonga is ACTIVE and switches fulltext_search to
 *   ENGINE=Mroonga. If TokenMecab is available, sets tokenizer via COMMENT.
 * - Drops/recreates FK on owner_id around ENGINE change.
 * - On uninstall: switches back to InnoDB and restores FK if missing.
 */
class Module extends AbstractModule {

  /**
   * Return module configuration.
   */
  public function getConfig(): array {
    return include __DIR__ . '/config/module.config.php';
  }

  /**
   * Module install hook.
   */
  public function install(ServiceLocatorInterface $serviceLocator): void {
    $connection = $serviceLocator->get('Omeka\\Connection');
    // Validate that Mroonga plugin is ACTIVE before writing settings.
    $this->checkMroongaPlugin($connection);
    $this->manageSettings($serviceLocator->get('Omeka\\Settings'), 'install');
    // Switch fulltext_search to Mroonga (handle FK and optional MeCab).
    $this->enableMroongaOnFulltextSearch($connection);
  }

  /**
   * Module uninstall hook.
   */
  public function uninstall(ServiceLocatorInterface $serviceLocator): void {
    $this->manageSettings($serviceLocator->get('Omeka\Settings'), 'uninstall');
    $connection = $serviceLocator->get('Omeka\Connection');
    // Drop FK if it exists (it may not exist while using Mroonga).
    $fkName = $this->findForeignKeyName($connection, 'fulltext_search', 'owner_id');
    if ($fkName) {
      $connection->executeStatement("ALTER TABLE fulltext_search DROP FOREIGN KEY `{$fkName}`");
    }
    // Switch back to InnoDB and clear comment.
    $connection->executeStatement("ALTER TABLE fulltext_search ENGINE=InnoDB COMMENT=''");
    // Recreate FK with a stable name if it doesn't exist.
    $fkName = $this->findForeignKeyName($connection, 'fulltext_search', 'owner_id');
    if (!$fkName) {
      $connection->executeStatement(
        "ALTER TABLE fulltext_search ADD CONSTRAINT fk_fulltext_search_owner FOREIGN KEY (`owner_id`) REFERENCES `user`(`id`) ON DELETE SET NULL"
      );
    }
  }

  /**
   * Manage module settings on install/uninstall.
   */
  protected function manageSettings($settings, $process, $key = 'config'): void {
    $config = require __DIR__ . '/config/module.config.php';
    $defaultSettings = $config[strtolower(__NAMESPACE__)][$key] ?? [];
    foreach ($defaultSettings as $name => $value) {
      switch ($process) {
        case 'install':
          $settings->set($name, $value);
          break;

        case 'uninstall':
          $settings->delete($name);
          break;
      }
    }
  }

  /**
   * Ensure Mroonga plugin is ACTIVE.
   */
  protected function checkMroongaPlugin(Connection $connection): void {
    $sql = "SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='Mroonga'";
    $result = method_exists($connection, 'fetchOne')
      ? $connection->fetchOne($sql)
      : $connection->fetchColumn($sql);
    if ($result !== 'ACTIVE') {
      $message = new Message('Mroonga is not installed or not ACTIVE. Please install and enable the Mroonga plugin in MySQL/MariaDB, then install this module. / Mroonga がインストールされていないか有効化されていません。先に MySQL/MariaDB 側で Mroonga プラグインをインストールして有効化し、このモジュールをインストールしてください。');
      throw new ModuleCannotInstallException($message);
    }
  }

  /**
   * Enable Mroonga engine on fulltext_search table (idempotent).
   */
  protected function enableMroongaOnFulltextSearch(Connection $connection): void {
    // If already Mroonga, do nothing (best-effort idempotent).
    $engine = $this->getTableEngine($connection, 'fulltext_search');
    if (strcasecmp($engine, 'Mroonga') === 0) {
      return;
    }

    // Drop FK on owner_id if exists.
    $fkName = $this->findForeignKeyName($connection, 'fulltext_search', 'owner_id');
    if ($fkName) {
      $connection->executeStatement("ALTER TABLE fulltext_search DROP FOREIGN KEY `{$fkName}`");
    }

    // Decide tokenizer comment if TokenMecab is available.
    $comment = '';
    if ($this->isTokenMecabAvailable($connection)) {
      $comment = " COMMENT='tokenizer \"TokenMecab\"'";
    }

    // Switch engine to Mroonga (with optional tokenizer).
    $sql = "ALTER TABLE fulltext_search ENGINE=Mroonga" . $comment;
    $connection->executeStatement($sql);
  }

  /**
   * Get storage engine for a table.
   */
  protected function getTableEngine(Connection $connection, string $table): string {
    $sql = 'SHOW TABLE STATUS LIKE :t';
    $row = $connection->fetchAssociative($sql, ['t' => $table]);
    return $row['Engine'] ?? '';
  }

  /**
   * Find FK name by table and local column.
   */
  protected function findForeignKeyName(Connection $connection, string $table, string $column): ?string {
    $sql = 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
      . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column '
      . 'AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1';
    $name = $connection->fetchOne($sql, ['table' => $table, 'column' => $column]);
    return $name ?: NULL;
  }

  /**
   * Detect TokenMecab availability (probe table create/drop).
   */
  protected function isTokenMecabAvailable(Connection $connection): bool {
    try {
      $connection->beginTransaction();
      $connection->executeStatement('CREATE TABLE IF NOT EXISTS __mecab_probe (f TEXT, FULLTEXT INDEX (f)) ENGINE=Mroonga COMMENT=\'tokenizer "TokenMecab"\'');
      $connection->executeStatement('DROP TABLE IF EXISTS __mecab_probe');
      $connection->commit();
      return TRUE;
    }
    catch (\Throwable $e) {
      if (method_exists($connection, 'isTransactionActive') ? $connection->isTransactionActive() : TRUE) {
        $connection->rollBack();
      }
    }
    return FALSE;
  }

}
