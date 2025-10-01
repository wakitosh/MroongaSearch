<?php

namespace MroongaSearch\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use MroongaSearch\Job\ReindexItemsOnly;
use MroongaSearch\Job\ReindexItemsAndSets;
use MroongaSearch\Job\ReindexMediaOnly;
use Laminas\View\Model\ViewModel;

/**
 * Admin controller for MroongaSearch utilities.
 */
class IndexController extends AbstractActionController {

  /**
   * Dispatch the items-only reindex job and redirect to jobs list.
   */
  public function reindexItemsAction() {
    $this->jobDispatcher()->dispatch(ReindexItemsOnly::class);
    $this->messenger()->addSuccess('Dispatched: Reindex items only');
    return $this->redirect()->toRoute('admin/default', ['controller' => 'job', 'action' => 'browse']);
  }

  /**
   * Dispatch the items+item sets reindex job.
   */
  public function reindexItemsSetsAction() {
    $this->jobDispatcher()->dispatch(ReindexItemsAndSets::class);
    $this->messenger()->addSuccess('Dispatched: Reindex items + item sets');
    return $this->redirect()->toRoute('admin/default', ['controller' => 'job', 'action' => 'browse']);
  }

  /**
   * Dispatch the media-only reindex job.
   */
  public function reindexMediaAction() {
    $this->jobDispatcher()->dispatch(ReindexMediaOnly::class);
    $this->messenger()->addSuccess('Dispatched: Reindex media only');
    return $this->redirect()->toRoute('admin/default', ['controller' => 'job', 'action' => 'browse']);
  }

  /**
   * Diagnostics panel: show plugin/engine/tokenizer/index status and counts.
   */
  public function diagnosticsAction() {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $conn = $services->get('Omeka\Connection');
    $em = $services->get('Omeka\EntityManager');
    // Resolve timezone from global settings with fallback to PHP default.
    $timezone = NULL;
    try {
      $settings = $services->get('Omeka\Settings');
      $tz = NULL;
      if ($settings && method_exists($settings, 'get')) {
        // Common key name in Omeka S; fallback to default if absent.
        $tz = $settings->get('timezone');
        if (!$tz) {
          $tz = $settings->get('time_zone');
        }
      }
      $timezone = $tz ?: \date_default_timezone_get();
    }
    catch (\Throwable $e) {
      $timezone = \date_default_timezone_get();
    }
    $pluginActive = FALSE;
    $effective = FALSE;
    $engine = '';
    $comment = '';
    $tokenMecab = FALSE;
    $fulltextIndexes = [];
    $counts = ['items' => NULL, 'item_sets' => NULL, 'media' => NULL];
    $totals = ['items' => NULL, 'item_sets' => NULL, 'media' => NULL];
    $jobs = [];

    // Plugin status.
    try {
      $sql = "SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='Mroonga'";
      $status = method_exists($conn, 'fetchOne') ? $conn->fetchOne($sql) : $conn->fetchColumn($sql);
      $pluginActive = ($status === 'ACTIVE');
    }
    catch (\Throwable $e) {
    }

    // Engine and comment.
    try {
      $row = $conn->fetchAssociative('SHOW TABLE STATUS LIKE :t', ['t' => 'fulltext_search']);
      if (is_array($row)) {
        $engine = (string) ($row['Engine'] ?? '');
        $comment = (string) ($row['Comment'] ?? '');
      }
      $effective = ($pluginActive && strcasecmp($engine, 'Mroonga') === 0);
    }
    catch (\Throwable $e) {
    }

    // TokenMecab probe (no transaction; DDL is autocommit).
    try {
      $conn->executeStatement('CREATE TABLE IF NOT EXISTS __mecab_probe (f TEXT, FULLTEXT INDEX (f)) ENGINE=Mroonga COMMENT=\'tokenizer "TokenMecab"\'');
      $conn->executeStatement('DROP TABLE IF EXISTS __mecab_probe');
      $tokenMecab = TRUE;
    }
    catch (\Throwable $e) {
      $tokenMecab = FALSE;
    }

    // Fulltext indexes.
    try {
      $sql = "SELECT INDEX_NAME, INDEX_TYPE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
              FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fulltext_search'
              GROUP BY INDEX_NAME, INDEX_TYPE";
      $rows = method_exists($conn, 'fetchAllAssociative') ? $conn->fetchAllAssociative($sql) : $conn->fetchAll($sql);
      foreach ((array) $rows as $r) {
        $fulltextIndexes[] = [
          'name' => (string) ($r['INDEX_NAME'] ?? ''),
          'type' => (string) ($r['INDEX_TYPE'] ?? ''),
          'cols' => (string) ($r['cols'] ?? ''),
        ];
      }
    }
    catch (\Throwable $e) {
    }

    // Resource counts.
    try {
      $rs = $conn->fetchAllAssociative("SELECT resource, COUNT(*) AS cnt FROM fulltext_search GROUP BY resource");
      foreach ($rs as $r) {
        $counts[(string) $r['resource']] = (int) $r['cnt'];
      }
    }
    catch (\Throwable $e) {
    }

    // Actual totals from core tables (items, item_set, media).
    try {
      $totals['items'] = (int) ($conn->fetchOne('SELECT COUNT(*) FROM item') ?? 0);
    }
    catch (\Throwable $e) {
    }
    try {
      $totals['item_sets'] = (int) ($conn->fetchOne('SELECT COUNT(*) FROM item_set') ?? 0);
    }
    catch (\Throwable $e) {
    }
    try {
      $totals['media'] = (int) ($conn->fetchOne('SELECT COUNT(*) FROM media') ?? 0);
    }
    catch (\Throwable $e) {
    }

    // Recent job summaries (latest 6 among Mroonga/Fulltext-related jobs).
    try {
      $classes = [
        'Omeka\\Job\\IndexFulltextSearch',
        'MroongaSearch\\Job\\ReindexItemsOnly',
        'MroongaSearch\\Job\\ReindexItemsAndSets',
        'MroongaSearch\\Job\\ReindexMediaOnly',
      ];
      $qb = $em->createQueryBuilder();
      $qb->select('j')
        ->from('Omeka\\Entity\\Job', 'j')
        ->where($qb->expr()->in('j.class', ':classes'))
        ->orderBy('j.id', 'DESC')
        ->setMaxResults(6)
        ->setParameter('classes', $classes);
      $res = $qb->getQuery()->getResult();
      foreach ((array) $res as $j) {
        // Avoid hard dependency on entity getters; use method_exists checks.
        $jid = method_exists($j, 'getId') ? (int) $j->getId() : NULL;
        $jclass = method_exists($j, 'getClass') ? (string) $j->getClass() : '';
        $jstatus = method_exists($j, 'getStatus') ? (string) $j->getStatus() : '';
        $jstarted = method_exists($j, 'getStarted') ? $j->getStarted() : NULL;
        $jended = method_exists($j, 'getEnded') ? $j->getEnded() : NULL;
        $jobs[] = [
          'id' => $jid,
          'class' => $jclass,
          'status' => $jstatus,
          'started' => $jstarted ? $jstarted : NULL,
          'ended' => $jended ? $jended : NULL,
        ];
      }
    }
    catch (\Throwable $e) {
    }

    $vm = new ViewModel([
      'pluginActive' => $pluginActive,
      'engine' => $engine,
      'comment' => $comment,
      'effective' => $effective,
      'tokenMecab' => $tokenMecab,
      'fulltextIndexes' => $fulltextIndexes,
      'counts' => $counts,
      'totals' => $totals,
      'timezone' => $timezone,
      'jobs' => $jobs,
    ]);
    $vm->setTemplate('mroonga-search/admin/diagnostics');
    return $vm;
  }

  /**
   * Switch fulltext_search engine to Mroonga (with optional TokenMecab).
   *
   * Then dispatch a fulltext reindex job.
   */
  public function switchEngineAction() {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $conn = $services->get('Omeka\\Connection');
    $logger = NULL;
    try {
      $logger = $services->get('Omeka\\Logger');
    }
    catch (\Throwable $e) {
    }

    // Check plugin status first.
    $pluginActive = FALSE;
    try {
      $sql = "SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='Mroonga'";
      $st = method_exists($conn, 'fetchOne') ? $conn->fetchOne($sql) : $conn->fetchColumn($sql);
      $pluginActive = ($st === 'ACTIVE');
    }
    catch (\Throwable $e) {
    }
    if (!$pluginActive) {
      $this->messenger()->addError('Mroonga plugin is not ACTIVE.');
      return $this->redirect()->toRoute('admin/mroonga-diagnostics');
    }

    // Drop FK if exists.
    try {
      $fk = $conn->fetchOne(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='fulltext_search' AND COLUMN_NAME='owner_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
      );
      if ($fk) {
        $conn->executeStatement("ALTER TABLE fulltext_search DROP FOREIGN KEY `{$fk}`");
      }
    }
    catch (\Throwable $e) {
    }

    // Decide comment options.
    $comment = ' COMMENT=\'table "ms_fulltext"\'';
    // Probe TokenMecab quickly.
    $hasMecab = FALSE;
    try {
      $conn->executeStatement('CREATE TABLE IF NOT EXISTS __mecab_probe (f TEXT, FULLTEXT INDEX (f)) ENGINE=Mroonga COMMENT=\'tokenizer "TokenMecab"\'');
      $conn->executeStatement('DROP TABLE IF EXISTS __mecab_probe');
      $hasMecab = TRUE;
    }
    catch (\Throwable $e) {
      $hasMecab = FALSE;
    }
    if ($hasMecab) {
      $comment = ' COMMENT=\'table "ms_fulltext" tokenizer "TokenMecab"\'';
    }

    // Try ALTER first.
    $alterOk = FALSE;
    try {
      $conn->executeStatement("ALTER TABLE fulltext_search ENGINE=Mroonga{$comment}");
      $alterOk = TRUE;
    }
    catch (\Throwable $e) {
      if ($logger) {
        $logger->warn('Mroonga switch: ALTER failed: ' . $e->getMessage());
      }
    }

    // If ALTER failed, try drop+recreate.
    if (!$alterOk) {
      try {
        try {
          $conn->executeQuery("SELECT mroonga_command('object_remove fulltext_search')");
        }
        catch (\Throwable $x) {
        }
        try {
          $conn->executeQuery("SELECT mroonga_command('object_remove ms_fulltext')");
        }
        catch (\Throwable $x) {
        }
        $conn->executeStatement('DROP TABLE IF EXISTS `fulltext_search`');
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
        $conn->executeStatement($create);
        $alterOk = TRUE;
      }
      catch (\Throwable $e) {
        if ($logger) {
          $logger->err('Mroonga switch: recreate failed: ' . $e->getMessage());
        }
        $this->messenger()->addError('Failed to switch engine to Mroonga. Check logs.');
        return $this->redirect()->toRoute('admin/mroonga-diagnostics');
      }
    }

    // Ensure FULLTEXT index exists (idempotent safety).
    try {
      $rows = $conn->fetchAllAssociative(
        "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fulltext_search' AND INDEX_TYPE='FULLTEXT' GROUP BY INDEX_NAME"
      );
      $hasFt = FALSE;
      foreach ($rows as $r) {
        if (strcasecmp((string) ($r['cols'] ?? ''), 'title,text') === 0) {
          $hasFt = TRUE;
          break;
        }
      }
      if (!$hasFt) {
        $conn->executeStatement('ALTER TABLE `fulltext_search` ADD FULLTEXT INDEX `ft_title_text` (`title`,`text`)');
      }
    }
    catch (\Throwable $e) {
    }

    // Reindex is now manual from Diagnostics page.
    $this->messenger()->addSuccess('Switched to Mroonga. Please run reindex jobs from the Diagnostics page.');

    return $this->redirect()->toRoute('admin/mroonga-diagnostics');
  }

  /**
   * Switch fulltext_search back to InnoDB (fallback mode) to test behavior.
   */
  public function switchInnoDbAction() {
    $services = $this->getEvent()->getApplication()->getServiceManager();
    $conn = $services->get('Omeka\\Connection');
    $logger = NULL;
    try {
      $logger = $services->get('Omeka\\Logger');
    }
    catch (\Throwable $e) {
    }

    // Drop FK if exists to allow engine change.
    try {
      $fk = $conn->fetchOne(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='fulltext_search' AND COLUMN_NAME='owner_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
      );
      if ($fk) {
        $conn->executeStatement("ALTER TABLE fulltext_search DROP FOREIGN KEY `{$fk}`");
      }
    }
    catch (\Throwable $e) {
    }

    // Try to switch engine back to InnoDB and clear comment.
    $ok = FALSE;
    try {
      $conn->executeStatement("ALTER TABLE fulltext_search ENGINE=InnoDB COMMENT=''");
      $ok = TRUE;
    }
    catch (\Throwable $e) {
      if ($logger) {
        $logger->warn('InnoDB switch: ALTER failed: ' . $e->getMessage());
      }
    }
    if (!$ok) {
      // Recreate as InnoDB if ALTER failed; data will need reindex.
      try {
        $conn->executeStatement('DROP TABLE IF EXISTS `fulltext_search`');
        $conn->executeStatement(
          'CREATE TABLE `fulltext_search` (
            `id` INT NOT NULL,
            `resource` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            `owner_id` INT DEFAULT NULL,
            `is_public` TINYINT(1) NOT NULL,
            `title` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `text` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            PRIMARY KEY (`id`, `resource`),
            KEY `IDX_AA31FE4A7E3C61F9` (`owner_id`),
            FULLTEXT KEY `IDX_AA31FE4A2B36786B3B8BA7C7` (`title`, `text`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ok = TRUE;
      }
      catch (\Throwable $e) {
        if ($logger) {
          $logger->err('InnoDB switch: recreate failed: ' . $e->getMessage());
        }
        $this->messenger()->addError('Failed to switch engine to InnoDB. Check logs.');
        return $this->redirect()->toRoute('admin/mroonga-diagnostics');
      }
    }

    // Restore FK if missing.
    try {
      $fk = $conn->fetchOne(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='fulltext_search' AND COLUMN_NAME='owner_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
      );
      if (!$fk) {
        $conn->executeStatement(
          "ALTER TABLE fulltext_search ADD CONSTRAINT fk_fulltext_search_owner FOREIGN KEY (`owner_id`) REFERENCES `user`(`id`) ON DELETE SET NULL"
        );
      }
    }
    catch (\Throwable $e) {
    }

    $this->messenger()->addSuccess('Switched to InnoDB (fallback mode). Please run reindex jobs if you recreated the table.');
    return $this->redirect()->toRoute('admin/mroonga-diagnostics');
  }

}
