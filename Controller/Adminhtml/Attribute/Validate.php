<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;
use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Model\Attribute\FormProcessor;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\AttributeFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\FormData;
use Magento\Store\Model\WebsiteFactory;

/**
 * AJAX validation of the attribute form before it is submitted. Returns {"error": bool, "message": string}.
 */
class Validate extends Attribute implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        EntityContext $entityContext,
        AttributeFactory $attributeFactory,
        Registry $coreRegistry,
        WebsiteFactory $websiteFactory,
        private readonly FormProcessor $formProcessor,
        private readonly FormData $formDataSerializer
    ) {
        parent::__construct($context, $entityContext, $attributeFactory, $coreRegistry, $websiteFactory);
    }

    public function execute()
    {
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $optionData = $this->formDataSerializer->unserialize((string) $this->getRequest()->getParam('serialized_options', '[]'));
        } catch (\InvalidArgumentException $e) {
            return $json->setData(['error' => true, 'message' => (string) __('The attribute could not be validated. Verify your information and try again.')]);
        }
        $post = array_replace_recursive($this->getRequest()->getPostValue(), $optionData);

        $existing = null;
        $attributeId = (int) $this->getRequest()->getParam('attribute_id');
        if ($attributeId) {
            $existing = $this->createAttribute()->load($attributeId);
            if (!$this->isManageable($existing)) {
                return $json->setData(['error' => true, 'message' => (string) __('This attribute no longer exists or cannot be edited.')]);
            }
        }

        $errors = $this->formProcessor->process($this->entityContext, $post, $existing)['errors'];
        return $json->setData([
            'error' => $errors !== [],
            'message' => implode(' ', array_map('strval', $errors)),
        ]);
    }
}
