<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;
use MageOS\CustomerCustomAttributes\Model\Dependency\RelationAttributes;
use MageOS\CustomerCustomAttributes\Model\Dependency\RelationFormWarnings;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\RelationStorage;
use MageOS\CustomerCustomAttributes\Service\RelationValidator;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Save extends Relation implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly RelationStorage $storage,
        private readonly RelationAttributes $attributes,
        private readonly RelationValidator $validator,
        private readonly RelationFormWarnings $warnings
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();
        $id = (int) $request->getParam('relation_id');

        // The entity of an existing relation cannot change.
        $existing = $id ? $this->storage->load($id) : null;
        if ($id && $existing === null) {
            $this->messageManager->addErrorMessage(__('This relation no longer exists.'));
            return $redirect->setPath('*/*/');
        }
        $entity = $existing['entity_type'] ?? (string) $request->getParam('entity_type');
        $name = trim((string) $request->getParam('name'));
        $rules = $this->readRules($request->getParam('rules'));

        $errors = $this->validator->validate(
            $name,
            $entity,
            $rules,
            $this->attributes->getParentOptions($entity),
            array_keys($this->attributes->getAttributes($entity)),
            $this->storage->loadRules(null, $entity, $id ?: null)
        );
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->messageManager->addErrorMessage(__($error['message'], ...$error['args']));
            }
            $this->_getSession()->setData(self::FORM_SESSION_KEY, [
                'relation_id' => $id,
                'name' => $name,
                'entity_type' => $entity,
                'rules' => $rules,
            ]);
            return $redirect->setPath('*/*/edit', $id ? ['relation_id' => $id] : []);
        }

        try {
            $id = $this->storage->save($id ?: null, $name, $entity, $rules, $this->attributes->getIds($entity));
            $this->messageManager->addSuccessMessage(__('You saved the relation.'));
            foreach ($this->warnings->forRules($entity, $rules) as $warning) {
                $this->messageManager->addWarningMessage($warning);
            }
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t save the relation right now.'));
            return $redirect->setPath('*/*/edit', $id ? ['relation_id' => $id] : []);
        }
        return $request->getParam('back')
            ? $redirect->setPath('*/*/edit', ['relation_id' => $id])
            : $redirect->setPath('*/*/');
    }

    /**
     * @return list<array{parent: string, option: string, dependent: string}>
     */
    private function readRules(mixed $posted): array
    {
        $rules = [];
        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rules[] = [
                'parent' => trim((string) ($row['parent'] ?? '')),
                'option' => trim((string) ($row['option'] ?? '')),
                'dependent' => trim((string) ($row['dependent'] ?? '')),
            ];
        }
        return $rules;
    }
}
