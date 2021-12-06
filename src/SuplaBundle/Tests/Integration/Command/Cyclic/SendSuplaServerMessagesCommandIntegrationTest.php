<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Tests\Integration\Command\Cyclic;

use SuplaBundle\Entity\IODevice;
use SuplaBundle\Entity\User;
use SuplaBundle\Message\UserOptOutNotifications;
use SuplaBundle\Tests\Integration\IntegrationTestCase;
use SuplaBundle\Tests\Integration\TestMailer;
use SuplaBundle\Tests\Integration\Traits\SuplaApiHelper;

/**
 * @small
 */
class SendSuplaServerMessagesCommandIntegrationTest extends IntegrationTestCase {
    use SuplaApiHelper;

    /** @var User */
    private $user;

    protected function initializeDatabaseForTests() {
        $this->initializeDatabaseWithMigrations();
        $this->user = $this->createConfirmedUser();
    }

    public function testSendingSuplaServerEmail() {
        $body = json_encode([
            'template' => UserOptOutNotifications::FAILED_AUTH_ATTEMPT,
            'userId' => $this->user->getId(),
            'data' => ['ip' => '12.23.34.45'],
        ]);
        $this->getEntityManager()->getConnection()->executeQuery(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) ' .
            "VALUES('$body', '[]', 'supla-server', NOW(), NOW())"
        );
        $this->flushMessagesQueue();
        $this->assertCount(0, TestMailer::getMessages());
        $this->executeCommand('supla:cyclic:send-server-messages');
        $this->assertCount(0, TestMailer::getMessages());
        $this->flushMessagesQueue();
        $this->assertCount(1, TestMailer::getMessages());
        $message = TestMailer::getMessages()[0];
        $this->assertStringContainsString('<b>12.23.34.45</b>', $message->getBody());
        $this->assertStringContainsString('<a href="mailto:security', $message->getBody());
    }

    public function testNewIoDeviceNotification() {
        $parameters = [
            $this->user->getLocations()[0]->getId(),
            $this->user->getId(),
            "'abc'",
            "'ZAMEL-PNW-CHOINKA'",
            "INET_ATON('1.1.2.2')",
            "'3.33'",
            10,
            'NULL',
            'NULL',
            'NULL',
            'NULL',
            'NULL',
            '@outId',
        ];
        $query = 'CALL supla_add_iodevice(' . implode(', ', $parameters) . ')';
        $this->getEntityManager()->getConnection()->executeQuery($query);
        $this->assertEquals('ZAMEL-PNW-CHOINKA', $this->getEntityManager()->find(IODevice::class, 1)->getName());
        $this->executeCommand('supla:cyclic:send-server-messages');
        $this->flushMessagesQueue();
        $this->assertCount(1, TestMailer::getMessages());
        $message = TestMailer::getMessages()[0];
        $this->assertStringContainsString('new device has been added', $message->getSubject());
        $this->assertStringContainsString('ZAMEL-PNW-CHOINKA', $message->getBody());
        $this->assertStringContainsString('3.33', $message->getBody());
    }
}