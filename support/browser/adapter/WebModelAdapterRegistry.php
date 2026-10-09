<?php
declare(strict_types=1);
namespace support\browser\adapter;

/** Single selection point for Web-model page rules; core task/MCP code never chooses DOM selectors. */
final class WebModelAdapterRegistry
{
    /** @return class-string<WebModelAdapterInterface> */
    public static function current(): string
    {
        return ChatGptWebAdapter::class;
    }

    /** @return class-string */
    public static function pageClass(): string
    {
        $adapter = self::current();
        $page = $adapter::pageClass();
        self::assertPageContract($page);
        return $page;
    }

    /** Fail fast if an adapter omits any page operation currently consumed by BrowserRuntime. */
    public static function assertPageContract(string $page): void
    {
        $required = [
            'capabilityExpression', 'modelControlStateExpression', 'focusEffortControlExpression', 'effortSliderExpression',
            'focusEffortSliderOwnerExpression', 'thinkStateExpression', 'thinkCommandMenuExpression', 'baselineExpression',
            'focusAndClearExpression', 'focusComposerExpression', 'insertPlainTextExpression', 'composerTextExpression',
            'promptTextExpression', 'sendReadinessExpression', 'focusSendExpression', 'connectorMenuExpression',
            'connectorSelectedExpression', 'toolConfirmationExpression', 'focusToolConfirmationExpression',
            'personalizationStateExpression', 'personalizationOpenExpression', 'personalizationMenuExpression',
            'personalizationSelectExpression', 'acknowledgementExpression', 'attachmentStateExpression',
            'responseExpression', 'focusArtifactDownloadExpression', 'markdownAfterToolBoundary', 'stableMarkdownPrefix',
            'temporaryChatUrl', 'isTemporaryChatUrl', 'isBackendUrl', 'isBackendMessageTooLarge', 'isBackendSecurityChallenge',
        ];
        foreach ($required as $method) {
            if (!is_callable([$page, $method])) throw new \LogicException('Web model adapter page contract missing: ' . $method);
        }
    }
}
