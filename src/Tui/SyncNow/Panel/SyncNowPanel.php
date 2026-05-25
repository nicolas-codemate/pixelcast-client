<?php

declare(strict_types=1);

namespace App\Tui\SyncNow\Panel;

use App\Tui\SyncNow\SyncNowResult;
use App\Tui\SyncNow\SyncNowResultKind;
use App\Tui\SyncNow\SyncTarget;
use App\Tui\TerminalSafeText;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class SyncNowPanel
{
    private const string HEADER_TEXT = 'Sync Now - [Enter] dispatch  [Esc] back';
    private const string SEPARATOR_TEXT = '----------------------------------------------------------------';
    private const string NEVER_DISPATCHED_LABEL = 'never';
    private const int LABEL_COLUMN_WIDTH = 22;

    private readonly TextWidget $resultLine;
    private readonly SelectListWidget $list;
    private readonly ContainerWidget $container;

    /**
     * @var array<string,string>
     */
    private array $lastDispatchByTargetId = [];

    public function __construct()
    {
        $header = new TextWidget(self::HEADER_TEXT);
        $separator = new TextWidget(self::SEPARATOR_TEXT);
        $this->list = new SelectListWidget(
            items: $this->buildSelectListItems(),
            maxVisible: \count(SyncTarget::cases()),
        );
        $this->resultLine = new TextWidget('');

        $this->container = new ContainerWidget();
        $this->container->expandVertically(true);
        $this->container->add($header);
        $this->container->add($separator);
        $this->container->add($this->list);
        $this->container->add($this->resultLine);
    }

    public function widget(): ContainerWidget
    {
        return $this->container;
    }

    public function selectListWidget(): SelectListWidget
    {
        return $this->list;
    }

    public function recordDispatch(SyncTarget $target, \DateTimeImmutable $dispatchedAt): void
    {
        $this->lastDispatchByTargetId[$target->value] = $dispatchedAt->format('H:i:s');

        $selectedTargetValue = $this->currentSelectedTargetValue();
        $this->list->setItems($this->buildSelectListItems());
        $this->restoreSelectionFor($selectedTargetValue);
    }

    public function lastDispatchLabelFor(SyncTarget $target): string
    {
        return $this->lastDispatchByTargetId[$target->value] ?? self::NEVER_DISPATCHED_LABEL;
    }

    public function showResult(SyncNowResult $result): void
    {
        $this->resultLine->setText(TerminalSafeText::stripControlBytes($this->formatResult($result)));
    }

    public function clearResult(): void
    {
        $this->resultLine->setText('');
    }

    public function currentResultText(): string
    {
        return $this->resultLine->getText();
    }

    private function currentSelectedTargetValue(): ?string
    {
        $selected = $this->list->getSelectedItem();

        return $selected['value'] ?? null;
    }

    private function restoreSelectionFor(?string $targetValue): void
    {
        if (null === $targetValue) {
            return;
        }

        foreach (SyncTarget::cases() as $index => $target) {
            if ($target->value === $targetValue) {
                $this->list->setSelectedIndex($index);

                return;
            }
        }
    }

    private function formatResult(SyncNowResult $result): string
    {
        return match ($result->kind) {
            SyncNowResultKind::Dispatched => 'OK '.$result->message.' dispatched',
            SyncNowResultKind::NotWired => 'NOT WIRED '.$result->message.' (class does not exist)',
            SyncNowResultKind::DispatchError => 'FAIL: '.$result->message,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildSelectListItems(): array
    {
        $items = [];
        foreach (SyncTarget::cases() as $target) {
            $items[] = [
                'value' => $target->value,
                'label' => \sprintf(
                    '%-'.self::LABEL_COLUMN_WIDTH.'s last: %s',
                    $target->label(),
                    $this->lastDispatchLabelFor($target),
                ),
            ];
        }

        return $items;
    }
}
