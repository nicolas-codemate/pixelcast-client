<?php

declare(strict_types=1);

namespace App\Tui\DeviceStatus\Panel;

use App\Tui\DeviceStatus\StatsFormatter;
use App\Tui\DeviceStatus\StatsSnapshot;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class DeviceStatusPanel
{
    private const string HEADER_BASE = 'Device Status';
    private const string HEADER_BUSY = 'Device Status  polling...';
    private const string SEPARATOR_TEXT = '----------------------------------------------------------------';
    private const string FOOTER_HINT = '[Esc] back';
    private const string NO_DATA_TEXT = 'No data';
    private const string UNREACHABLE_TEXT = 'Unreachable';

    private readonly TextWidget $header;
    private readonly TextWidget $body;
    private readonly ContainerWidget $container;

    public function __construct()
    {
        $this->header = new TextWidget(self::HEADER_BASE);
        $this->body = new TextWidget(self::NO_DATA_TEXT);

        $this->container = new ContainerWidget();
        $this->container->expandVertically(true);
        $this->container->add($this->header);
        $this->container->add(new TextWidget(self::SEPARATOR_TEXT));
        $this->container->add($this->body);
        $this->container->add(new TextWidget(self::FOOTER_HINT));
    }

    public function widget(): AbstractWidget
    {
        return $this->container;
    }

    public function update(?StatsSnapshot $snapshot, bool $busy): void
    {
        $this->header->setText($busy ? self::HEADER_BUSY : self::HEADER_BASE);

        if (null === $snapshot) {
            $this->body->setText(self::NO_DATA_TEXT);

            return;
        }

        if (!$snapshot->reachable) {
            $this->body->setText(self::UNREACHABLE_TEXT);

            return;
        }

        $this->body->setText(StatsFormatter::format($snapshot));
    }

    public function headerText(): string
    {
        return $this->header->getText();
    }

    public function bodyText(): string
    {
        return $this->body->getText();
    }
}
