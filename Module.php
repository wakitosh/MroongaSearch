<?php

declare(strict_types=1);

namespace MroongaSearch;

use Doctrine\DBAL\Connection;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Form\SettingForm;
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
   * PSR-4 style autoloader configuration for this module.
   */
  public function getAutoloaderConfig(): array {
    return [
      'Laminas\\Loader\\StandardAutoloader' => [
        'namespaces' => [
          __NAMESPACE__ => __DIR__ . '/src',
        ],
      ],
    ];
  }

  /**
   * Attach strict full-text tweaks after core has built its query.
   */
  public function onBootstrap(MvcEvent $event): void {
    parent::onBootstrap($event);
    $application = $event->getApplication();
    // If the DB doesn't have Mroonga but the table engine is Mroonga,
    // revert it to InnoDB to keep search working.
    try {
      $services = $application->getServiceManager();
      /** @var \Doctrine\DBAL\Connection $conn */
      $conn = $services->get('Omeka\\Connection');
      if (!$this->isMroongaActive($conn)) {
        $engine = $this->getTableEngine($conn, 'fulltext_search');
        if (strcasecmp($engine, 'Mroonga') === 0) {
          try {
            $logger = $services->get('Omeka\\Logger');
            if ($logger) {
              $logger->warn('MroongaSearch: fulltext_search is ENGINE=Mroonga but plugin is not active. Reverting to InnoDB.');
            }
          }
          catch (\Throwable $ignore) {
            // Ignore logger errors.
          }
          $this->revertMroongaOnFulltextSearch($conn);
        }
      }
      else {
        // If plugin is active but table is not Mroonga, switch automatically.
        $engine = $this->getTableEngine($conn, 'fulltext_search');
        if (strcasecmp($engine, 'Mroonga') !== 0) {
          try {
            $logger = $services->get('Omeka\\Logger');
            if ($logger) {
              $logger->info('MroongaSearch: Mroonga plugin is active. Switching fulltext_search to Mroonga engine.');
            }
          }
          catch (\Throwable $ignore) {
          }
          try {
            $this->enableMroongaOnFulltextSearch($conn);
          }
          catch (\Throwable $ignore) {
            // Keep running even if ALTER fails; fallback search still works.
          }
        }
      }
    }
    catch (\Throwable $e) {
      try {
        $logger = isset($services) ? $services->get('Omeka\\Logger') : NULL;
        if ($logger) {
          $logger->err('MroongaSearch: Failed to check or revert fulltext_search engine: ' . $e->getMessage());
        }
      }
      catch (\Throwable $ignore) {
        // Ignore logger errors.
      }
      // Best-effort: ignore and continue.
    }
    $shared = $application->getEventManager()->getSharedManager();

    // Early guard: Prevent core natural-language fulltext from running when.
    // we intend to handle strict semantics ourselves.
    // - Non-Mroonga: 常に退避（従来通り）。
    // - Mroonga: トークン数が2個以上のときは退避（strict AND/OR を当モジュールで実装）。
    // こうすることで、コアの自然言語条件と当モジュールの条件が二重適用されるのを防ぎます.
    try {
      $services = $application->getServiceManager();
      $self = $this;
      $shared->attach('*', 'api.search.query', function ($ev) use ($services, $self) {
        try {
          $request = $ev->getParam('request');
          if (!$request) {
            return;
          }
          $conn = $services->get('Omeka\\Connection');
          $query = $request->getContent() ?: [];
          $full = isset($query['fulltext_search']) ? trim((string) $query['fulltext_search']) : '';
          if ($full === '') {
            return;
          }
          $tokens = $self->tokenizeFulltext($full);
          // Treat Mroonga as "effective" only when plugin is ACTIVE and
          // the fulltext_search table engine is actually Mroonga.
          $mroongaEffective = $self->isMroongaEffective($conn);
          $shouldDivert = FALSE;
          if ($mroongaEffective) {
            // Mroonga が有効でトークン数が2以上なら、strict AND/OR は当モジュールが担当.
            $shouldDivert = count($tokens) >= 2;
          }
          else {
            // 非Mroongaでは常に退避してコア自然言語検索を抑止します.
            $shouldDivert = TRUE;
          }
          if (!$shouldDivert) {
            return;
          }
          // Remove fulltext_search from the request to prevent core
          // natural-mode fulltext from running. Keep it into `ms_fulltext`.
          $query = $request->getContent() ?: [];
          $query['ms_fulltext'] = $query['fulltext_search'];
          unset($query['fulltext_search']);
          $request->setContent($query);
        }
        catch (\Throwable $e) {
          // Ignore and continue.
        }
      }, 10000);
    }
    catch (\Throwable $e) {
      // Ignore and continue.
    }
    // Run after core listeners (use low/negative priority)
    // to keep visibility and ordering intact.
    $services = $application->getServiceManager();
    $self = $this;
    $shared->attach('*', 'api.search.query', function ($ev) use ($services, $self) {
      try {
        // Skip if Mroonga is not active to avoid touching fulltext_search.
        $conn = $services->get('Omeka\\Connection');
        if (!$self->isMroongaEffective($conn)) {
          return;
        }
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
        // Read diverted key first to avoid core natural-mode double filtering.
        $full = '';
        if (isset($query['ms_fulltext'])) {
          $full = trim((string) $query['ms_fulltext']);
        }
        elseif (isset($query['fulltext_search'])) {
          $full = trim((string) $query['fulltext_search']);
        }
        if ($full === '') {
          return;
        }

        // Tokenize; if single token/phrase, let core handle default behavior.
        $tokens = $this->tokenizeFulltext($full);
        if (count($tokens) <= 1) {
          return;
        }

        // Strict AND/OR based on UI param: `fulltext_logic` (fallback `logic`).
        $logic = strtolower((string) ($query['fulltext_logic'] ?? ($query['logic'] ?? 'and')));
        $useOr = ($logic === 'or');

        // Join fulltext table and require presence per-token.
        $alias = $adapter->createAlias();
        // Compare resource column with the API resource name (e.g., 'items'),
        // because fulltext_search.resource stores API names by default.
        $resourceName = $adapter->getResourceName();
        $joinConditions = sprintf(
          '%s.id = omeka_root.id AND %s.resource = %s',
          $alias,
          $alias,
          $adapter->createNamedParameter($qb, $resourceName)
        );
        $qb->innerJoin('Omeka\\Entity\\FulltextSearch', $alias, 'WITH', $joinConditions);

        /* Build grouped conditions: each token MATCH(...) AGAINST (token) > 0.
         * Use natural mode (no BOOLEAN MODE) for broader compatibility.
         * Mroonga will tokenize appropriately when available.
         */
        $perToken = [];
        foreach ($tokens as $tok) {
          $match = sprintf(
            'MATCH(%s.title, %s.text) AGAINST (%s) > 0',
            $alias,
            $alias,
            $adapter->createNamedParameter($qb, $tok)
          );
          $perToken[] = $match;
        }
        $glue = $useOr ? ' OR ' : ' AND ';
        $qb->andWhere('(' . implode($glue, $perToken) . ')');
      }
      catch (\Throwable $e) {
        // Fail silently to avoid breaking search in case of unexpected issues.
      }
    }, -100);

    // Fallback strict AND/OR for non-Mroonga environments using BOOLEAN MODE.
    // This augments core natural-mode matching to differentiate AND vs OR.
    $shared->attach('*', 'api.search.query', function ($ev) use ($services, $self) {
      try {
        $conn = $services->get('Omeka\\Connection');
        // When Mroonga is effectively available (plugin+engine), let the
        // Mroonga-specific listener above handle strict logic.
        if ($self->isMroongaEffective($conn)) {
          // Mroonga listener above will handle strict logic.
          return;
        }
        // If the table engine is Mroonga but the plugin is not active,
        // avoid touching fulltext_search at all to prevent engine errors.
        try {
          $engine = (string) $self->getTableEngine($conn, 'fulltext_search');
          if (strcasecmp($engine, 'Mroonga') === 0) {
            return;
          }
        }
        catch (\Throwable $ee) {
          // If engine detection fails, be conservative and do nothing.
          return;
        }
        $adapter = $ev->getTarget();
        if (!method_exists($adapter, 'createAlias') || !method_exists($adapter, 'getResourceName') || !method_exists($adapter, 'createNamedParameter')) {
          return;
        }
        $request = $ev->getParam('request');
        $qb = $ev->getParam('queryBuilder');
        if (!$request || !$qb) {
          return;
        }
        $query = $request->getContent();
        // Read from our private key first,
        // then fallback to fulltext_search/search.
        $full = '';
        if (isset($query['ms_fulltext'])) {
          $full = trim((string) $query['ms_fulltext']);
        }
        elseif (isset($query['fulltext_search'])) {
          $full = trim((string) $query['fulltext_search']);
        }
        elseif (isset($query['search'])) {
          $full = trim((string) $query['search']);
        }
        if ($full === '') {
          return;
        }
        $tokens = $self->tokenizeFulltext($full);
        $logic = strtolower((string) ($query['fulltext_logic'] ?? ($query['logic'] ?? 'and')));
        $useOr = ($logic === 'or');

        // Join a separate alias to apply constraints per-token.
        $alias = $adapter->createAlias();
        // Compare resource column with the API resource name (e.g., 'items'),
        // because fulltext_search.resource stores API names by default.
        $resourceName = $adapter->getResourceName();
        $joinConditions = sprintf(
          '%s.id = omeka_root.id AND %s.resource = %s',
          $alias,
          $alias,
          $adapter->createNamedParameter($qb, $resourceName)
        );
        $qb->innerJoin('Omeka\\Entity\\FulltextSearch', $alias, 'WITH', $joinConditions);

        $perToken = [];
        foreach ($tokens as $tok) {
          if ($self->isCjkContinuous($tok)) {
            // CJK fallback: substring match via LIKE.
            $like = $adapter->createNamedParameter($qb, '%' . $tok . '%');
            $perToken[] = sprintf('(%s.title LIKE %s OR %s.text LIKE %s)', $alias, $like, $alias, $like);
          }
          else {
            // Non-CJK: use BOOLEAN MODE (> 0 means present).
            $perToken[] = sprintf(
              'MATCH(%s.title, %s.text) AGAINST (%s IN BOOLEAN MODE) > 0',
              $alias,
              $alias,
              $adapter->createNamedParameter($qb, $tok)
            );
          }
        }
        $glue = $useOr ? ' OR ' : ' AND ';
        $qb->andWhere('(' . implode($glue, $perToken) . ')');
      }
      catch (\Throwable $e) {
        // Ignore to avoid breaking search in edge cases.
      }
    }, -120);
  }

  /**
   * Attach listeners to extend admin SettingForm with a warning.
   */
  public function attachListeners(SharedEventManagerInterface $sharedEventManager): void {
    // Add an informational warning next to the core "Index full-text search"
    // checkbox to prevent accidental full reindex on production.
    $sharedEventManager->attach(
      SettingForm::class,
      'form.add_elements',
      function ($event) {
        try {
          $form = $event->getTarget();
          if (!method_exists($form, 'has') || !method_exists($form, 'get')) {
            return;
          }
          if (!$form->has('index_fulltext_search')) {
            return;
          }
          $element = $form->get('index_fulltext_search');
          $opts = $element->getOptions() ?: [];
          // Bilingual caution message (JA/EN).
          $opts['info'] =
            '推奨: この全量再インデックスは実行中に検索結果が不安定になります。管理メニューの「Mroonga: Reindex items only / items + item sets / media only」を順に使って段階的に再構築してください。' .
            ' / Caution: Full reindex will temporarily disrupt search results. Prefer the segmented admin jobs: Mroonga: Reindex items only / items + item sets / media only.';
          $element->setOptions($opts);
        }
        catch (\Throwable $e) {
          // Best-effort only: ignore if form structure changes.
        }
      },
      // Priority: run late so core and other modules add their elements first.
      -100
    );
  }

  /**
   * Best-effort detection of Mroonga plugin availability.
   */
  protected function isMroongaActive(Connection $connection): bool {
    try {
      $sql = "SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='Mroonga'";
      $result = method_exists($connection, 'fetchOne')
        ? $connection->fetchOne($sql)
        : $connection->fetchColumn($sql);
      return $result === 'ACTIVE';
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Determine if Mroonga is effectively usable for searches.
   *
   * This requires both:
   * - The Mroonga plugin is ACTIVE, and
   * - The fulltext_search table engine is Mroonga.
   *
   * When only the plugin is active but the table remains InnoDB, we should
   * not consider Mroonga effectively available and must use the fallback
   * logic (e.g., CJK LIKE for single-term queries).
   */
  protected function isMroongaEffective(Connection $connection): bool {
    try {
      if (!$this->isMroongaActive($connection)) {
        return FALSE;
      }
      $engine = (string) $this->getTableEngine($connection, 'fulltext_search');
      return strcasecmp($engine, 'Mroonga') === 0;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Revert fulltext_search from Mroonga to InnoDB safely.
   */
  protected function revertMroongaOnFulltextSearch(Connection $connection): void {
    try {
      // Best-effort: remove any orphan Groonga objects that may conflict later.
      try {
        $connection->executeQuery("SELECT mroonga_command('object_remove fulltext_search')");
      }
      catch (\Throwable $ignore) {
      }
      try {
        $connection->executeQuery("SELECT mroonga_command('object_remove ms_fulltext')");
      }
      catch (\Throwable $ignore) {
      }
      // Drop FK if it exists.
      $fkName = $this->findForeignKeyName($connection, 'fulltext_search', 'owner_id');
      if ($fkName) {
        $connection->executeStatement("ALTER TABLE fulltext_search DROP FOREIGN KEY `{$fkName}`");
      }
      // Try switching back to InnoDB and clear comment.
      $connection->executeStatement("ALTER TABLE fulltext_search ENGINE=InnoDB COMMENT='' ");
      // Recreate FK if missing.
      $fkName = $this->findForeignKeyName($connection, 'fulltext_search', 'owner_id');
      if (!$fkName) {
        $connection->executeStatement(
          "ALTER TABLE fulltext_search ADD CONSTRAINT fk_fulltext_search_owner FOREIGN KEY (`owner_id`) REFERENCES `user`(`id`) ON DELETE SET NULL"
        );
      }
    }
    catch (\Throwable $e) {
      // When ALTER fails due to unknown engine, rebuild the table in InnoDB.
      // Data will be re-populated by the indexing job.
      try {
        // Best-effort logging.
        /** @var \Laminas\Log\LoggerInterface|null $logger */
        $logger = NULL;
        try {
          $logger = $this->getServiceLocator()->get('Omeka\\Logger');
        }
        catch (\Throwable $ignore) {
        }
        if ($logger) {
          $logger->warn('MroongaSearch: ALTER TABLE to InnoDB failed, recreating `fulltext_search` as InnoDB (data will be reindexed). Error: ' . $e->getMessage());
        }

        // Drop and recreate the table with the core schema.
        $connection->executeStatement('DROP TABLE IF EXISTS `fulltext_search`');
        $connection->executeStatement(
          'CREATE TABLE `fulltext_search` (
            `id` INT NOT NULL,
            `resource` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            `owner_id` INT DEFAULT NULL,
            `is_public` TINYINT(1) NOT NULL,
            `title` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `text` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            PRIMARY KEY (`id`, `resource`),
            KEY `IDX_AA31FE4A7E3C61F9` (`owner_id`),
            FULLTEXT KEY `IDX_AA31FE4A2B36786B3B8BA7C7` (`title`, `text`),
            CONSTRAINT `FK_AA31FE4A7E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Do NOT auto-dispatch a full reindex here: it may take many hours.
        // Instead, guide admins to run segmented reindex jobs from Diagnostics.
        if ($logger) {
          $logger->warn('MroongaSearch: fulltext_search was recreated as InnoDB. Please run manual reindex from Admin > Mroonga Search > Indexing.');
        }
      }
      catch (\Throwable $fatal) {
        // Final fallback: swallow to avoid breaking the app at bootstrap.
      }
    }
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
    $this->manageSettings($serviceLocator->get('Omeka\\Settings'), 'install');
    // If Mroonga is active, switch fulltext_search to Mroonga
    // (handle FK and optional MeCab).
    // Otherwise, keep InnoDB and just log a notice; the runtime
    // fallback will work.
    try {
      if ($this->isMroongaActive($connection)) {
        $this->enableMroongaOnFulltextSearch($connection);
      }
      else {
        try {
          $logger = $serviceLocator->get('Omeka\\Logger');
          if ($logger) {
            $logger->warn('MroongaSearch: Mroonga plugin is not active. Installing module in fallback mode (InnoDB).');
          }
        }
        catch (\Throwable $ignore) {
        }
      }
    }
    catch (\Throwable $e) {
      // Do not fail installation; runtime will work in fallback mode.
      try {
        $logger = $serviceLocator->get('Omeka\\Logger');
        if ($logger) {
          $logger->err('MroongaSearch: Error while enabling Mroonga on install: ' . $e->getMessage());
        }
      }
      catch (\Throwable $ignore) {
      }
    }
  }

  /**
   * Module uninstall hook.
   */
  public function uninstall(ServiceLocatorInterface $serviceLocator): void {
    $this->manageSettings($serviceLocator->get('Omeka\\Settings'), 'uninstall');
    $connection = $serviceLocator->get('Omeka\\Connection');
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
      // Keep method for compatibility, but throw with a string to
      // avoid TypeError.
      throw new ModuleCannotInstallException((string) $message);
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

    // Decide COMMENT options: always pin a distinct Groonga table name to avoid
    // name conflicts with past objects; add TokenMecab when available.
    $commentOpts = [
      'table "ms_fulltext"',
    ];
    if ($this->isTokenMecabAvailable($connection)) {
      $commentOpts[] = 'tokenizer "TokenMecab"';
    }
    $comment = " COMMENT='" . implode(' ', $commentOpts) . "'";

    // Switch engine to Mroonga (with optional tokenizer).
    $sql = "ALTER TABLE fulltext_search ENGINE=Mroonga" . $comment;
    try {
      $connection->executeStatement($sql);
      // Ensure required FULLTEXT index exists after engine switch.
      $this->ensureFulltextIndex($connection, 'fulltext_search', ['title', 'text']);
    }
    catch (\Throwable $e) {
      // If direct ALTER fails (e.g. due to metadata/incompatibilities),
      // recreate the table as Mroonga and reindex.
      try {
        /** @var \Laminas\Log\LoggerInterface|null $logger */
        $logger = NULL;
        try {
          $logger = $this->getServiceLocator()->get('Omeka\\Logger');
        }
        catch (\Throwable $ignore) {
        }
        if ($logger) {
          $logger->warn('MroongaSearch: ALTER TABLE to Mroonga failed, recreating `fulltext_search` as Mroonga (data will be reindexed). Error: ' . $e->getMessage());
        }

        // Best-effort: remove any orphan Groonga objects that may conflict.
        try {
          // Try removing both the previous default name and our pinned name.
          $connection->executeQuery("SELECT mroonga_command('object_remove fulltext_search')");
        }
        catch (\Throwable $ignore) {
        }
        try {
          $connection->executeQuery("SELECT mroonga_command('object_remove ms_fulltext')");
        }
        catch (\Throwable $ignore) {
        }

        // Drop and recreate the table with the same schema but ENGINE=Mroonga.
        $connection->executeStatement('DROP TABLE IF EXISTS `fulltext_search`');
        $create = "CREATE TABLE `fulltext_search` (
            `id` INT NOT NULL,
            `resource` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            `owner_id` INT DEFAULT NULL,
            `is_public` TINYINT(1) NOT NULL,
            `title` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `text` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            PRIMARY KEY (`id`, `resource`),
            KEY `IDX_AA31FE4A7E3C61F9` (`owner_id`),
            FULLTEXT KEY `IDX_AA31FE4A2B36786B3B8BA7C7` (`title`, `text`)
          ) ENGINE=Mroonga DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" . $comment;
        $connection->executeStatement($create);

        // Ensure FULLTEXT index exists (it should from CREATE, but keep
        // idempotent).
        $this->ensureFulltextIndex(
          $connection,
          'fulltext_search',
          ['title', 'text']
        );

        // Do NOT auto-dispatch a full reindex here: it may take many hours.
        // Instead, guide admins to run segmented reindex jobs from Diagnostics.
        if ($logger) {
          $logger->warn('MroongaSearch: fulltext_search was recreated as Mroonga. Please run manual reindex from Admin > Mroonga Search > Indexing.');
        }
      }
      catch (\Throwable $fatal) {
        // Final fallback: swallow to avoid breaking the app at bootstrap.
      }
    }
  }

  /**
   * Check if a FULLTEXT index exists for the given table/columns.
   *
   * The column list must match exactly in order.
   */
  protected function hasFulltextIndex(Connection $connection, string $table, array $columns): bool {
    try {
      $colsOrdered = implode(',', $columns);
      $sql = "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols\n"
        . "FROM information_schema.STATISTICS\n"
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_TYPE = 'FULLTEXT'\n"
        . "GROUP BY INDEX_NAME";
      $rows = method_exists($connection, 'fetchAllAssociative')
        ? $connection->fetchAllAssociative($sql, ['t' => $table])
        : $connection->fetchAll($sql, ['t' => $table]);
      foreach ($rows as $r) {
        $cols = isset($r['cols']) ? (string) $r['cols'] : '';
        if (strcasecmp($cols, $colsOrdered) === 0) {
          return TRUE;
        }
      }
    }
    catch (\Throwable $e) {
      // Ignore and treat as missing.
    }
    return FALSE;
  }

  /**
   * Ensure FULLTEXT index exists; create it when missing.
   */
  protected function ensureFulltextIndex(Connection $connection, string $table, array $columns): void {
    if (empty($columns)) {
      return;
    }
    if ($this->hasFulltextIndex($connection, $table, $columns)) {
      return;
    }
    $quotedCols = array_map(
      function ($c) {
        return '`' . str_replace('`', '``', $c) . '`';
      },
      $columns
    );
    $indexName = 'ft_' . implode('_', $columns);
    $sql = sprintf('ALTER TABLE `%s` ADD FULLTEXT INDEX `%s` (%s)',
      str_replace('`', '``', $table),
      str_replace('`', '``', $indexName),
      implode(', ', $quotedCols)
    );
    try {
      $connection->executeStatement($sql);
    }
    catch (\Throwable $e) {
      // Best-effort: ignore failures; search may still work if another
      // index exists.
    }
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
    $name = method_exists($connection, 'fetchOne')
      ? $connection->fetchOne($sql, ['table' => $table, 'column' => $column])
      : $connection->fetchColumn($sql, ['table' => $table, 'column' => $column]);
    return $name ?: NULL;
  }

  /**
   * Detect TokenMecab availability (probe table create/drop).
   */
  protected function isTokenMecabAvailable(Connection $connection): bool {
    try {
      if (method_exists($connection, 'beginTransaction')) {
        $connection->beginTransaction();
      }
      $connection->executeStatement('CREATE TABLE IF NOT EXISTS __mecab_probe (f TEXT, FULLTEXT INDEX (f)) ENGINE=Mroonga COMMENT=\'tokenizer "TokenMecab"\'');
      $connection->executeStatement('DROP TABLE IF EXISTS __mecab_probe');
      if (method_exists($connection, 'commit')) {
        $connection->commit();
      }
      return TRUE;
    }
    catch (\Throwable $e) {
      if (method_exists($connection, 'isTransactionActive') ? $connection->isTransactionActive() : TRUE) {
        if (method_exists($connection, 'rollBack')) {
          $connection->rollBack();
        }
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
    // Extract quoted phrases using a simple parser.
    // It supports \" inside quotes.
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
          $ph = trim(str_replace('\\"', '"', $buf));
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
   *
   * Kept for potential future use.
   */
  protected function isCjkContinuous(string $str): bool {
    $str = trim($str);
    if ($str === '') {
      return FALSE;
    }
    if (preg_match('/\s/u', $str)) {
      return FALSE;
    }
    if (!preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\x{3005}\x{3006}\x{30F6}\x{30FC}]+$/u', $str)) {
      return FALSE;
    }
    if (function_exists('mb_strlen')) {
      return mb_strlen($str, 'UTF-8') >= 1;
    }
    return strlen($str) >= 1;
  }

}
