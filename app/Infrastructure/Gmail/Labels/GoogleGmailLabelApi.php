<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels;

use App\Domain\GmailLabels\Data\GmailLabelDefinition;
use App\Infrastructure\Gmail\Labels\Contracts\GmailLabelApi;
use App\Infrastructure\Gmail\Labels\Exceptions\GmailLabelApiFailure;
use Google\Service\Gmail;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\ModifyMessageRequest;
use Throwable;

final readonly class GoogleGmailLabelApi implements GmailLabelApi
{
    public function __construct(private Gmail $gmail) {}

    /** @return list<GmailLabelDefinition> */
    public function listLabels(): array
    {
        try {
            $labels = $this->gmail->users_labels->listUsersLabels('me')->getLabels() ?? [];

            return array_map(
                static fn (Label $label): GmailLabelDefinition => new GmailLabelDefinition(
                    id: (string) $label->getId(),
                    name: (string) $label->getName(),
                    custom: $label->getType() === Label::TYPE_user,
                ),
                $labels,
            );
        } catch (Throwable) {
            throw new GmailLabelApiFailure;
        }
    }

    public function createLabel(string $name): GmailLabelDefinition
    {
        try {
            $label = new Label;
            $label->setName($name);
            $created = $this->gmail->users_labels->create('me', $label);

            if ($created->getType() !== Label::TYPE_user) {
                throw new GmailLabelApiFailure;
            }

            return new GmailLabelDefinition(
                id: (string) $created->getId(),
                name: (string) $created->getName(),
                custom: true,
            );
        } catch (Throwable) {
            throw new GmailLabelApiFailure;
        }
    }

    /** @return list<string> */
    public function messageLabelIds(string $gmailMessageId): array
    {
        try {
            $message = $this->gmail->users_messages->get('me', $gmailMessageId, ['format' => 'minimal']);

            return array_values(array_filter($message->getLabelIds() ?? [], 'is_string'));
        } catch (Throwable) {
            throw new GmailLabelApiFailure;
        }
    }

    public function modifyMessageLabels(string $gmailMessageId, array $addLabelIds, array $removeLabelIds): void
    {
        if ($addLabelIds === [] && $removeLabelIds === []) {
            return;
        }

        try {
            $request = new ModifyMessageRequest;

            if ($addLabelIds !== []) {
                $request->setAddLabelIds($addLabelIds);
            }

            if ($removeLabelIds !== []) {
                $request->setRemoveLabelIds($removeLabelIds);
            }

            $this->gmail->users_messages->modify('me', $gmailMessageId, $request);
        } catch (Throwable) {
            throw new GmailLabelApiFailure;
        }
    }
}
