<?php

declare(strict_types=1);

namespace MroongaSearch;

use Doctrine\DBAL\Connection;
use Laminas\Mvc\MvcEvent;
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
   * Attach AND/OR aware full-text tweaks after core has built its query.
   */
  public function onBootstrap(MvcEvent $event): void {
    parent::onBootstrap($event);
    $application = $event->getApplication();
    $shared = $application->getEventManager()->getSharedManager();
    // Run after core listeners (use low/negative priority).
    $shared->attach('*', 'api.search.query', function ($ev) {
      try {
        $adapter = $ev->getTarget();
        // Ensure this is an entity adapter (has required API methods).
        if (!method_exists($adapter, 'createAlias') || !method_exists($adapter, 'getResourceName') || !method_exists($adapter, 'createNamedParameter')) {
          return;
        }
        $request = $ev->getParam('request');
        $qb = $ev->getParam('queryBuilder');
        if (!$request || !$qb) {
          return;
        }
        $query = $request->getContent();
        $full = isset($query['fulltext_search']) ? trim((string) $query['fulltext_search']) : '';
        if ($full == '') {
          return;
        }
        // Force AND for multi-token queries regardless of incoming 'logic'.
        // Single continuous CJK term: require phrase match (AND/OR regardless).
        $tokens = $this->tokenizeFulltext($full);
        if (count($tokens) <= 1) {
          // 単一語のときはコアの自然言語検索（デフォルト動作）に任せる。
          // 以前は連続CJKでフレーズを強制していたが撤廃した。.
          return;
        }
        // Logic === 'and': enforce all tokens presence using extra MATCH>0.
        $alias = $adapter->createAlias();
        $joinConditions = sprintf(
          '%s.id = omeka_root.id AND %s.resource = %s',
          $alias,
          $alias,
          $adapter->createNamedParameter($qb, $adapter->getResourceName())
        );
        $qb->innerJoin('Omeka\\Entity\\FulltextSearch', $alias, 'WITH', $joinConditions);
        foreach ($tokens as $tok) {
          $match = sprintf('MATCH(%s.title, %s.text) AGAINST (%s)',
            $alias,
            $alias,
            $adapter->createNamedParameter($qb, $tok)
          );
          $qb->andWhere($match . ' > 0');
        }
      }
      catch (\Throwable $e) {
        // Fail silently to avoid breaking search in case of unexpected issues.
      }
    }, -100);
  }

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

  /**
   * Tokenize a full-text query into words and quoted phrases.
   */
  protected function tokenizeFulltext(string $str): array {
    $str = trim($str);
    if ($str === '') {
      return [];
    }
    $tokens = [];
    // Extract quoted phrases first using a simple parser
    // (supports \" inside quotes).
    $consumedChars = [];
    $in = FALSE;
    $escape = FALSE;
    $buf = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
      $ch = $str[$i];
      if ($in) {
        if ($escape) {
          $buf .= $ch;
          $escape = FALSE;
          continue;
        }
        if ($ch === '\\') {
          $escape = TRUE;
          continue;
        }
        if ($ch === '"') {
          $in = FALSE;
          $ph = trim(str_replace('\"', '"', $buf));
          if ($ph !== '') {
            $tokens[] = $ph;
          }
          $buf = '';
          $consumedChars[] = ' ';
          continue;
        }
        // Inside quotes: accumulate into buffer, do not add to consumed.
        $buf .= $ch;
        continue;
      }
      // Not in quotes.
      if ($ch === '"') {
        $in = TRUE;
        $consumedChars[] = ' ';
        continue;
      }
      $consumedChars[] = $ch;
    }
    $consumed = implode('', $consumedChars);
    // Split remaining by whitespace.
    foreach (preg_split('/\s+/u', $consumed) as $w) {
      $w = trim($w);
      if ($w !== '') {
        $tokens[] = $w;
      }
    }
    // De-duplicate and cap.
    $tokens = array_values(array_unique($tokens));
    if (count($tokens) > 20) {
      $tokens = array_slice($tokens, 0, 20);
    }
    return $tokens;
  }

  /**
   * Check if the string is a single continuous CJK term (no spaces, length>=2).
   */
  protected function isCjkContinuous(string $str): bool {
    $str = trim($str);
    if ($str === '') {
      return FALSE;
    }
    // Must not contain whitespace.
    if (preg_match('/\s/u', $str)) {
      return FALSE;
    }
    // Allow only CJK scripts and common Japanese marks.
    if (!preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}々〆ヶー]+$/u', $str)) {
      return FALSE;
    }
    // At least 2 code points to avoid extremely short noisy tokens.
    if (function_exists('mb_strlen')) {
      return mb_strlen($str, 'UTF-8') >= 2;
    }
    // Fallback; byte length is acceptable here.
    return strlen($str) >= 2;
  }

}
