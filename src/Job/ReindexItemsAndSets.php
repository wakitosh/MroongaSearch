<?php

namespace MroongaSearch\Job;

use Omeka\Api\Adapter\FulltextSearchableInterface;
use Omeka\Api\Adapter\ResourceAdapter;
use Omeka\Api\Adapter\ValueAnnotationAdapter;
use Omeka\Job\AbstractJob;

/**
 * Reindex items and item sets only.
 */
class ReindexItemsAndSets extends AbstractJob {

  /**
   * Delete items/item_sets rows then refill.
   */
  public function perform(): void {
    $services = $this->getServiceLocator();
    $api = $services->get('Omeka\\ApiManager');
    $em = $services->get('Omeka\\EntityManager');
    $conn = $services->get('Omeka\\Connection');
    $fulltext = $services->get('Omeka\\FulltextSearch');
    $adapters = $services->get('Omeka\\ApiAdapterManager');

    $conn->executeStatement("DELETE FROM `fulltext_search` WHERE `resource` IN ('items','item_sets')");

    // 総件数（items + item_sets).
    $total = 0;
    try {
      $t1 = $api->search('items', ['page' => 1, 'per_page' => 1]);
      $total += method_exists($t1, 'getTotalResults') ? (int) $t1->getTotalResults() : 0;
    }
    catch (\Throwable $e) {
      // Ignore.
    }
    try {
      $t2 = $api->search('item_sets', ['page' => 1, 'per_page' => 1]);
      $total += method_exists($t2, 'getTotalResults') ? (int) $t2->getTotalResults() : 0;
    }
    catch (\Throwable $e) {
      // Ignore.
    }
    $processed = 0;
    $this->job->addLog('Reindex items+item_sets: start (total=' . $total . ')');
    $em->flush();

    foreach ($adapters->getRegisteredNames() as $adapterName) {
      $adapter = $adapters->get($adapterName);
      if ($adapter instanceof FulltextSearchableInterface
          && !($adapter instanceof ResourceAdapter)
          && !($adapter instanceof ValueAnnotationAdapter)
      ) {
        $r = method_exists($adapter, 'getResourceName') ? $adapter->getResourceName() : '';
        if ($r === 'items' || $r === 'item_sets') {
          $page = 1;
          do {
            if ($this->shouldStop()) {
              $this->job->addLog('Reindex items+item_sets: stopping (processed=' . $processed . '/' . $total . ', page=' . $page . ', resource=' . $r . ')');
              $em->flush();
              return;
            }
            $response = $api->search($r, ['page' => $page, 'per_page' => 100], ['responseContent' => 'resource']);
            foreach ($response->getContent() as $resource) {
              $fulltext->save($resource, $adapter);
            }
            $count = is_array($response->getContent()) ? count($response->getContent()) : 0;
            $processed += $count;
            $this->job->addLog('Reindex items+item_sets: processed ' . $processed . ' / ' . $total . ' (page=' . $page . ', resource=' . $r . ')');
            $em->flush();
            $em->clear();
            $this->job = $em->getRepository('Omeka\\Entity\\Job')->find($this->job->getId());
            $page++;
          } while ($response->getContent());
        }
      }
    }
    $this->job->addLog('Reindex items+item_sets: completed (processed=' . $processed . ' / ' . $total . ')');
    $em->flush();
  }

}
