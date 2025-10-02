<?php

namespace MroongaSearch\Job;

use Omeka\Api\Adapter\FulltextSearchableInterface;
use Omeka\Api\Adapter\ResourceAdapter;
use Omeka\Api\Adapter\ValueAnnotationAdapter;
use Omeka\Job\AbstractJob;

/**
 * Reindex only item sets into fulltext_search.
 */
class ReindexItemSetsOnly extends AbstractJob {

  /**
   * Perform reindex for item sets only.
   */
  public function perform(): void {
    $services = $this->getServiceLocator();
    $api = $services->get('Omeka\\ApiManager');
    $em = $services->get('Omeka\\EntityManager');
    $conn = $services->get('Omeka\\Connection');
    $fulltext = $services->get('Omeka\\FulltextSearch');
    $adapters = $services->get('Omeka\\ApiAdapterManager');

    // Clear existing item_sets rows.
    $conn->executeStatement("DELETE FROM `fulltext_search` WHERE `resource` = 'item_sets'");

    // Total count for item_sets.
    $total = 0;
    try {
      $t = $api->search('item_sets', ['page' => 1, 'per_page' => 1]);
      $total = method_exists($t, 'getTotalResults') ? (int) $t->getTotalResults() : 0;
    }
    catch (\Throwable $e) {
    }
    $processed = 0;
    $this->job->addLog('Reindex item_sets: start (total=' . $total . ')');
    $em->flush();

    foreach ($adapters->getRegisteredNames() as $adapterName) {
      $adapter = $adapters->get($adapterName);
      if ($adapter instanceof FulltextSearchableInterface
        && !($adapter instanceof ResourceAdapter)
        && !($adapter instanceof ValueAnnotationAdapter)
      ) {
        if (method_exists($adapter, 'getResourceName') && $adapter->getResourceName() === 'item_sets') {
          $page = 1;
          do {
            if ($this->shouldStop()) {
              $this->job->addLog('Reindex item_sets: stopping (processed=' . $processed . '/' . $total . ', page=' . $page . ')');
              $em->flush();
              return;
            }
            $response = $api->search('item_sets', ['page' => $page, 'per_page' => 100], ['responseContent' => 'resource']);
            foreach ($response->getContent() as $resource) {
              $fulltext->save($resource, $adapter);
            }
            $count = is_array($response->getContent()) ? count($response->getContent()) : 0;
            $processed += $count;
            $this->job->addLog('Reindex item_sets: processed ' . $processed . ' / ' . $total . ' (page=' . $page . ')');
            $em->flush();
            $em->clear();
            $this->job = $em->getRepository('Omeka\\Entity\\Job')->find($this->job->getId());
            $page++;
          } while ($response->getContent());
          $this->job->addLog('Reindex item_sets: completed (processed=' . $processed . ' / ' . $total . ')');
          $em->flush();
        }
      }
    }
  }

}
