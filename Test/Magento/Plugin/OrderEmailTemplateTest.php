<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Plugin;

use MageOS\CustomerCustomAttributes\Plugin\OrderEmailTemplate;
use MageOS\CustomerCustomAttributes\Service\EmailTemplateInserter;
use Magento\Email\Model\Template;
use PHPUnit\Framework\TestCase;

/**
 * The new order email template gets the "Additional Information" variable for one rendering when the variable has a value; the stored
 * template text is never changed.
 */
class OrderEmailTemplateTest extends TestCase
{
    private const TEXT = '<table class="order-details"><tr><td class="method-info">Payment</td></tr></table>';

    private function template(): Template
    {
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setTemplateText(self::TEXT);
        return $template;
    }

    public function testTheVariableIsInTheTextWhileRenderingAndGoneAfterwards(): void
    {
        $template = $this->template();
        $seen = null;
        $result = (new OrderEmailTemplate(new EmailTemplateInserter()))->aroundGetProcessedTemplate(
            $template,
            function (array $variables) use ($template, &$seen): string {
                $seen = $template->getTemplateText();
                return 'rendered';
            },
            ['mageos_additional_information_html' => '<tr>section</tr>']
        );

        self::assertSame('rendered', $result);
        self::assertStringContainsString('{{var mageos_additional_information_html|raw}}', (string) $seen);
        self::assertSame(self::TEXT, $template->getTemplateText(), 'the stored text is restored');
    }

    public function testTheTextIsRestoredWhenTheRenderingFails(): void
    {
        $template = $this->template();
        try {
            (new OrderEmailTemplate(new EmailTemplateInserter()))->aroundGetProcessedTemplate(
                $template,
                static function (): string {
                    throw new \LogicException('template error');
                },
                ['mageos_additional_information_html' => '<tr>section</tr>']
            );
            self::fail('the error passes through');
        } catch (\LogicException) {
            self::assertSame(self::TEXT, $template->getTemplateText());
        }
    }

    public function testATemplateWithTheVariableOrWithoutTheMethodCellsIsLeftAlone(): void
    {
        foreach (['<p>{{var mageos_additional_information_html|raw}}</p>', '<p>a custom template</p>'] as $text) {
            $template = $this->template();
            $template->setTemplateText($text);
            $seen = null;
            (new OrderEmailTemplate(new EmailTemplateInserter()))->aroundGetProcessedTemplate(
                $template,
                function () use ($template, &$seen): string {
                    $seen = $template->getTemplateText();
                    return '';
                },
                ['mageos_additional_information_html' => '<tr>section</tr>']
            );
            self::assertSame($text, $seen);
            self::assertSame($text, $template->getTemplateText());
        }
    }

    public function testWithoutAValueTheTemplateIsNotTouched(): void
    {
        $template = $this->template();
        $seen = null;
        foreach ([[], ['mageos_additional_information_html' => '']] as $variables) {
            (new OrderEmailTemplate(new EmailTemplateInserter()))->aroundGetProcessedTemplate(
                $template,
                function () use ($template, &$seen): string {
                    $seen = $template->getTemplateText();
                    return '';
                },
                $variables
            );
            self::assertSame(self::TEXT, $seen);
        }
    }
}
