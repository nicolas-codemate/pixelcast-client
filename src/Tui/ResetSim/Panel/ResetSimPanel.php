<?php

declare(strict_types=1);

namespace App\Tui\ResetSim\Panel;

use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\ScenarioResultFormatter;
use App\Tui\TerminalSafeText;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class ResetSimPanel
{
    private const string HEADER_TEXT = 'Reset Simulator - [Enter] confirm  [Esc] back';
    private const string SEPARATOR_TEXT = '----------------------------------------------------------------';
    private const string CONFIRM_ITEM_VALUE = 'reset';
    private const string CONFIRM_ITEM_LABEL = '[Enter] POST /__reset';

    private readonly TextWidget $resultLine;
    private readonly SelectListWidget $list;
    private readonly ContainerWidget $container;

    public function __construct()
    {
        $items = [
            [
                'value' => self::CONFIRM_ITEM_VALUE,
                'label' => self::CONFIRM_ITEM_LABEL,
            ],
        ];

        $header = new TextWidget(self::HEADER_TEXT);
        $separator = new TextWidget(self::SEPARATOR_TEXT);
        $this->list = new SelectListWidget(items: $items, maxVisible: \count($items));
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

    public function showResult(ScenarioResult $result): void
    {
        $this->resultLine->setText(TerminalSafeText::stripControlBytes(ScenarioResultFormatter::format($result)));
    }

    public function clearResult(): void
    {
        $this->resultLine->setText('');
    }

    public function currentResultText(): string
    {
        return $this->resultLine->getText();
    }
}
