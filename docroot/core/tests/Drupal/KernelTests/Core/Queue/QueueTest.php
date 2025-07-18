<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Core\Queue;

use Drupal\Core\Database\Database;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\Memory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Queues and unqueues a set of items to check the basic queue functionality.
 *
 * @group Queue
 */
class QueueTest extends KernelTestBase {

  /**
   * Tests the System queue.
   */
  public function testSystemQueue(): void {
    // Create two queues.
    $queue1 = new DatabaseQueue($this->randomMachineName(), Database::getConnection());
    $queue1->createQueue();
    $queue2 = new DatabaseQueue($this->randomMachineName(), Database::getConnection());
    $queue2->createQueue();

    $this->runQueueTest($queue1, $queue2);
  }

  /**
   * Tests the Memory queue.
   */
  public function testMemoryQueue(): void {
    // Create two queues.
    $queue1 = new Memory($this->randomMachineName());
    $queue1->createQueue();
    $queue2 = new Memory($this->randomMachineName());
    $queue2->createQueue();

    $this->runQueueTest($queue1, $queue2);
  }

  /**
   * Queues and unqueues a set of items to check the basic queue functionality.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue1
   *   An instantiated queue object.
   * @param \Drupal\Core\Queue\QueueInterface $queue2
   *   An instantiated queue object.
   */
  protected function runQueueTest($queue1, $queue2) {
    // Create four items.
    $data = [];
    for ($i = 0; $i < 4; $i++) {
      $data[] = [$this->randomMachineName() => $this->randomMachineName()];
    }

    // Queue items 1 and 2 in the queue1.
    $queue1->createItem($data[0]);
    $queue1->createItem($data[1]);

    // Retrieve two items from queue1.
    $items = [];
    $new_items = [];

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    // First two dequeued items should match the first two items we queued.
    $this->assertEquals(2, $this->queueScore($data, $new_items), 'Two items matched');

    // Add two more items.
    $queue1->createItem($data[2]);
    $queue1->createItem($data[3]);

    $this->assertSame(4, $queue1->numberOfItems(), 'Queue 1 is not empty after adding items.');
    $this->assertSame(0, $queue2->numberOfItems(), 'Queue 2 is empty while Queue 1 has items');

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    $items[] = $item = $queue1->claimItem();
    $new_items[] = $item->data;

    // All dequeued items should match the items we queued exactly once,
    // therefore the score must be exactly 4.
    $this->assertEquals(4, $this->queueScore($data, $new_items), 'Four items matched');

    // There should be no duplicate items.
    $this->assertEquals(4, $this->queueScore($new_items, $new_items), 'Four items matched');

    // Delete all items from queue1.
    foreach ($items as $item) {
      $queue1->deleteItem($item);
    }

    // Check that both queues are empty.
    $this->assertSame(0, $queue1->numberOfItems(), 'Queue 1 is empty');
    $this->assertSame(0, $queue2->numberOfItems(), 'Queue 2 is empty');

    // Test that we can claim an item that is expired, and we cannot claim an
    // item that has not expired yet.
    $queue1->createItem($data[0]);
    $item = $queue1->claimItem();
    $this->assertNotFalse($item, 'The item can be claimed.');
    $item = $queue1->claimItem();
    $this->assertFalse($item, 'The item cannot be claimed again.');
    // Set the expiration date to the current time minus the lease time plus 1
    // second. It should be possible to reclaim the item.
    $this->setExpiration($queue1, \Drupal::time()->getCurrentTime() - (QueueWorkerManagerInterface::DEFAULT_QUEUE_CRON_LEASE_TIME + 1));
    $item = $queue1->claimItem();
    $this->assertNotFalse($item, 'Item can be claimed after expiration.');
  }

  /**
   * Set the expiration for different queues.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue for which to alter the expiration.
   * @param int $expire
   *   The new expiration time.
   *
   * @throws \ReflectionException
   */
  protected function setExpiration($queue, $expire) {
    $class = get_class($queue);
    switch ($class) {
      case Memory::class:
        $reflection = new \ReflectionClass($queue);
        $property = $reflection->getProperty('queue');
        $property->setAccessible(TRUE);
        $items = $property->getValue($queue);
        end($items)->expire = $expire;
        break;

      case DatabaseQueue::class:
        \Drupal::database()
          ->update(DatabaseQueue::TABLE_NAME)
          ->fields(['expire' => $expire])
          ->execute();
        break;
    }
  }

  /**
   * Returns the number of equal items in two arrays.
   */
  protected function queueScore($items, $new_items) {
    $score = 0;
    foreach ($items as $item) {
      foreach ($new_items as $new_item) {
        if ($item === $new_item) {
          $score++;
        }
      }
    }
    return $score;
  }

}
