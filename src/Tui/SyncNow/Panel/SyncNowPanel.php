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
        $this->list->setItems($this->buildSelectListItems());
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
            $lastDispatch = $this->lastDispatchByTargetId[$target->value] ?? self::NEVER_DISPATCHED_LABEL;
            $items[] = [
                'value' => $target->value,
                'label' => \sprintf('%-22s last: %s', $target->label(), $lastDispatch),
            ];
        }

        return $items;
    }
}
