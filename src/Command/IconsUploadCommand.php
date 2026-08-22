<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Exception\InvalidPayloadException;
use App\Client\Exception\PixelcastClientException;
use App\Client\Icon\IconUpload;
use App\Client\PixelcastClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:icons:upload',
    description: 'Uploads a local PNG or GIF onto the device filesystem under a given icon name',
)]
final class IconsUploadCommand extends Command
{
    public function __construct(
        private readonly PixelcastClientInterface $pixelcastClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name the device stores the icon under, letters, digits, dashes and underscores only')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the PNG or GIF file to upload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $iconName */
        $iconName = $input->getArgument('name');
        /** @var string $iconFilePath */
        $iconFilePath = $input->getArgument('path');

        // InvalidPayloadException implements PixelcastClientException, so the local check keeps its own
        // try block to stay an INVALID rather than collapsing into the device FAILURE below.
        try {
            $iconUpload = IconUpload::fromFile($iconFilePath, $iconName);
        } catch (InvalidPayloadException $unusableFile) {
            $io->error($unusableFile->getMessage());

            return Command::INVALID;
        }

        try {
            $this->pixelcastClient->uploadIcon($iconUpload);
        } catch (PixelcastClientException $uploadFailure) {
            $io->error(\sprintf('The device did not take the icon: %s', $uploadFailure->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Icon "%s" is now on the device as %s.', $iconUpload->name, $iconUpload->fileName()));

        return Command::SUCCESS;
    }
}
