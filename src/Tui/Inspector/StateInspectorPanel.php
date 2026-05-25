<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class StateInspectorPanel
{
    private readonly TextWidget $header;
    private readonly TextWidget $body;
    private readonly ContainerWidget $container;

    public function __construct()
    {
        $this->header = new TextWidget('State Inspector');
        $this->body = new TextWidget('No data');
        $this->container = new ContainerWidget();
        $this->container->add($this->header);
        $this->container->add($this->body);
    }

    public function widget(): AbstractWidget
    {
        return $this->container;
    }

    public function update(?InspectorSnapshot $snapshot, bool $busy): void
    {
        $this->header->setText($busy ? 'State Inspector  polling...' : 'State Inspector');

        if (null === $snapshot) {
            $this->body->setText('No data');

            return;
        }

        if (!$snapshot->reachable) {
            $this->body->setText('Unreachable');

            return;
        }

        $this->body->setText(StateFormatter::format($snapshot->state));
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
