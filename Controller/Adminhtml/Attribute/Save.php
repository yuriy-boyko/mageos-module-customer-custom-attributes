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
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeGroupStorage;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use MageOS\CustomerCustomAttributes\Service\WebsiteScopeNormalizer;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\AttributeFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\FormData;
use Magento\Store\Model\WebsiteFactory;

/**
 * Creates or updates a user-defined attribute from the edit form.
 */
class Save extends Attribute implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        EntityContext $entityContext,
        AttributeFactory $attributeFactory,
        Registry $coreRegistry,
        WebsiteFactory $websiteFactory,
        private readonly FormProcessor $formProcessor,
        private readonly FormData $formDataSerializer,
        private readonly AttributeGroupStorage $groupStorage,
        private readonly AttributeWebsiteStorage $websiteStorage,
        private readonly WebsiteScopeNormalizer $websiteNormalizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig
    ) {
        parent::__construct($context, $entityContext, $attributeFactory, $coreRegistry, $websiteFactory);
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $params = ['entity' => $this->entityContext->getKey()];
        $attributeId = (int) $this->getRequest()->getParam('attribute_id');
        $formPath = $attributeId ? '*/*/edit' : '*/*/new';
        $formParams = $attributeId ? $params + ['attribute_id' => $attributeId] : $params;

        try {
            $optionData = $this->formDataSerializer->unserialize((string) $this->getRequest()->getParam('serialized_options', '[]'));
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage(__('The attribute could not be saved. Verify your information and try again.'));
            return $redirect->setPath($formPath, $formParams);
        }

        $post = $this->getRequest()->getPostValue();
        if (!$this->getRequest()->isPost() || !$post) {
            return $redirect->setPath('*/*/', $params);
        }
        $post = array_replace_recursive($post, $optionData);

        $attribute = $this->createAttribute();
        if ($attributeId) {
            $attribute->load($attributeId);
            if (!$this->isManageable($attribute)) {
                $this->messageManager->addErrorMessage(__('This attribute no longer exists or cannot be edited.'));
                return $redirect->setPath('*/*/', $params);
            }
        }

        $result = $this->formProcessor->process($this->entityContext, $post, $attributeId ? $attribute : null);
        $scope = ['rows' => [], 'errors' => []];
        if ($attributeId && isset($post['scope']) && is_array($post['scope'])) {
            $websiteIds = array_map(static fn ($website): int => (int) $website->getId(), $this->storeManager->getWebsites());
            $scope = $this->websiteNormalizer->normalize($post['scope'], (string) $attribute->getFrontendInput(), $websiteIds);
            $result['errors'] = array_merge($result['errors'], array_map(static fn (string $message): string => (string) __($message), $scope['errors']));
        }
        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->messageManager->addErrorMessage($error);
            }
            $this->_getSession()->setAttributeData($post);
            return $redirect->setPath($formPath, $formParams);
        }

        try {
            $attribute->addData($result['data']);
            $attribute->save();
            $groups = $post['customer_groups'] ?? [];
            $this->groupStorage->save((int) $attribute->getId(), is_array($groups) ? $groups : []);
            $this->websiteStorage->save((int) $attribute->getId(), $scope['rows']);
            // The attribute definitions are cached per website.
            $this->eavConfig->clear();
            $this->messageManager->addSuccessMessage(__('You saved the attribute.'));
            $this->_getSession()->setAttributeData(false);

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', $params + ['attribute_id' => $attribute->getId()]);
            }
            return $redirect->setPath('*/*/', $params);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t save the attribute right now.'));
        }
        $this->_getSession()->setAttributeData($post);
        return $redirect->setPath($formPath, $formParams);
    }
}
