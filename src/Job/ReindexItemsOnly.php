<?php

namespace MroongaSearch\Job;

use Omeka\Api\Adapter\FulltextSearchableInterface;
use Omeka\Api\Adapter\ResourceAdapter;
use Omeka\Api\Adapter\ValueAnnotationAdapter;
use Omeka\Job\AbstractJob;

/**
 * Reindex only items into fulltext_search.
 *
 * Deletes existing rows for resource='items' then refills them.
 */
class ReindexItemsOnly extends AbstractJob {

  /**
   * Perform reindex for items only.
   */
  public function perform(): void {
    $services = $this->getServiceLocator();
    $api = $services->get('Omeka\\ApiManager');
    $em = $services->get('Omeka\\EntityManager');
    $conn = $services->get('Omeka\\Connection');
    $fulltext = $services->get('Omeka\\FulltextSearch');
    $adapters = $services->get('Omeka\\ApiAdapterManager');

    // 1) items の既存インデックスを削除
    $conn->executeStatement("DELETE FROM `fulltext_search` WHERE `resource` = 'items'");

    // 総件数を取得して開始ログを追加.
    try {
      $totalResp = $api->search('items', ['page' => 1, 'per_page' => 1]);
      $total = method_exists($totalResp, 'getTotalResults') ? (int) $totalResp->getTotalResults() : 0;
    }
    catch (\Throwable $e) {
      $total = 0;
    }
    $processed = 0;
    $this->job->addLog('Reindex items: start (total=' . $total . ')');
    $em->flush();

    // 2) items アダプタだけ走査して再投入
    foreach ($adapters->getRegisteredNames() as $adapterName) {
      $adapter = $adapters->get($adapterName);
      if ($adapter instanceof FulltextSearchableInterface
            && !($adapter instanceof ResourceAdapter)
            && !($adapter instanceof ValueAnnotationAdapter)
        ) {
        if (method_exists($adapter, 'getResourceName') && $adapter->getResourceName() === 'items') {
          $page = 1;
          do {
            if ($this->shouldStop()) {
              // 停止要求: 現在の進捗を記録して終了.
              $this->job->addLog('Reindex items: stopping (processed=' . $processed . '/' . $total . ', page=' . $page . ')');
              $em->flush();
              return;
            }
            $response = $api->search(
                  $adapter->getResourceName(),
                  ['page' => $page, 'per_page' => 100],
                  ['responseContent' => 'resource']
              );
            foreach ($response->getContent() as $resource) {
              $fulltext->save($resource, $adapter);
            }
            $count = is_array($response->getContent()) ? count($response->getContent()) : 0;
            $processed += $count;
            $this->job->addLog('Reindex items: processed ' . $processed . ' / ' . $total . ' (page=' . $page . ')');
            $em->flush();
            $em->clear();
            // clear() で Job エンティティがデタッチされるため再アタッチ.
            $this->job = $em->getRepository('Omeka\\Entity\\Job')->find($this->job->getId());
            $page++;
          } while ($response->getContent());
          $this->job->addLog('Reindex items: completed (processed=' . $processed . ' / ' . $total . ')');
          $em->flush();
        }
      }
    }
  }

}
