<?php

require_once 'lineitemedit.civix.php';
use CRM_Lineitemedit_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function lineitemedit_civicrm_config(&$config) {
  _lineitemedit_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function lineitemedit_civicrm_install() {
  _lineitemedit_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function lineitemedit_civicrm_enable() {
  _lineitemedit_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_permission().
 *
 */
function lineitemedit_civicrm_permission(&$permissions) {
  $permissions['add line item'] = [
    'label' => E::ts('Edit Line Item: add line item'),
    'description' => E::ts('Gives non-admin users to add line item(s) associated to a contribution.'),
  ];
  $permissions['edit line item'] = [
    'label' => E::ts('Edit Line Item: edit line item'),
    'description' => E::ts('Gives non-admin users to edit line item(s) associated to a contribution.'),
  ];
  $permissions['cancel line item'] = [
    'label' => E::ts('Edit Line Item: cancel line item'),
    'description' => E::ts('Gives non-admin users to cancel line item(s) associated to a contribution.'),
  ];
}

/**
 * Implements hook_civicrm_container().
 */
function lineitemedit_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
  $container->setDefinition("cache.lineitemEditor", new Symfony\Component\DependencyInjection\Definition(
    'CRM_Utils_Cache_Interface',
    [
      [
        'name' => 'lineitem-editor',
        'type' => ['*memory*', 'SqlGroup', 'ArrayCache'],
      ],
    ]
  ))->setFactory('CRM_Utils_Cache::create')->setPublic(TRUE);
}

function lineitemedit_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_Search') {
    Civi::resources()->addBundle('bootstrap3');
  }

  if ($formName == 'CRM_Contribute_Form_Contribution') {
    $contributionID = NULL;
    if (!empty($form->_id) && ($form->_action & CRM_Core_Action::UPDATE) && CRM_Core_Permission::check('edit line item')) {
      $contributionID = $form->_id;
      $pricesetFieldsCount = NULL;
      $isQuickConfig = empty($form->_lineItems) ? TRUE : FALSE;
      // Append line-item table only if current contribution has quick config lineitem
      if ($isQuickConfig) {
        $order = civicrm_api3('Order', 'getsingle', array('id' => $contributionID));
        $lineItemTable = CRM_Lineitemedit_Util::getLineItemTableInfo($order);
        $form->assign('lineItemTable', $lineItemTable);

        // Assumes templates are in a templates folder relative to this file
        $templatePath = realpath(dirname(__FILE__) . "/templates");
        $form->assign('pricesetFieldsCount', FALSE);
      }
      else {
        $pricesetFieldsCount = CRM_Core_Smarty::singleton()->getTemplateVars('pricesetFieldsCount');
        CRM_Lineitemedit_Util::formatLineItemList($form->_lineItems, $pricesetFieldsCount);
        $form->assign('lineItem', $form->_lineItems);
        $form->assign('pricesetFieldsCount', TRUE);
      }
      if (!empty($form->_values['total_amount'])) {
        $form->setDefaults('total_amount', $form->_values['total_amount']);
      }
    }

    if (!($form->_action & CRM_Core_Action::DELETE) && CRM_Core_Permission::check('cancel line item')) {
      $form->assign('contribution_id',$contributionID);
      Civi::service('angularjs.loader')->addModules(['afLineItems', 'afLineItemsTax']);

      $form->assign('editUrl', CRM_Utils_System::url('civicrm/lineitem/edit?reset=1&id=',NULL,FALSE,NULL,FALSE));
      $form->assign('cancelUrl', CRM_Utils_System::url('civicrm/lineitem/cancel?reset=1&id=',NULL,FALSE,NULL,FALSE));

      CRM_Lineitemedit_Util::buildLineItemRows($form, $contributionID);
      // assign this value so Smarty can properly iterate
      $form->assign('lineItemNumber', Civi::settings()->get('line_item_number'));
      CRM_Core_Region::instance('page-body')->add([
        'template' => "CRM/Lineitemedit/Form/AddLineItems.tpl",
      ]);
    }
  }
}

function lineitemedit_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_Contribution' &&
    !empty($form->_id) &&
    ($form->_action & CRM_Core_Action::UPDATE) &&
    CRM_Core_Permission::check('edit line item')
  ) {
    $lineItems = CRM_Price_BAO_LineItem::getLineItemsByContributionID($form->_id);
    foreach ($lineItems as $id => $lineItem) {
      if ($lineItem['qty'] == 0 && $lineItem['line_total'] != 0) {
        $qtyRatio = ($lineItem['line_total'] / $lineItem['unit_price']);
        if ($lineItem['html_type'] == 'Text') {
          $qtyRatio = round($qtyRatio, 2);
        }
        else {
          $qtyRatio = (int) $qtyRatio;
        }
        civicrm_api3('LineItem', 'create', array(
          'id' => $id,
          'qty' => $qtyRatio ? $qtyRatio : 1,
        ));
      }
    }
  }
  if ('CRM_Batch_Form_Entry' == $formName) {
    CRM_Lineitemedit_Util::disableEnablePriceField(TRUE);
  }
}

function lineitemedit_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ('CRM_Batch_Form_Entry' == $formName && empty($errors)) {
    CRM_Lineitemedit_Util::disableEnablePriceField();
  }
}

function lineitemedit_civicrm_pre($op, $entity, $entityID, &$params) {
  if ($entity == 'Contribution') {
    if ($op == 'create' && CRM_Core_Permission::check('add line item') && empty($params['price_set_id'])) {
      $lineItemParams = [];
      $financialTypes = [];
      $taxEnabled = (bool) Civi::settings()->get('invoicing');
      for ($i = 0; $i <= Civi::settings()->get('line_item_number'); $i++) {
        $lineItemParams[$i] = [];
        $notFound = TRUE;
        foreach (['item_label', 'item_financial_type_id', 'item_qty', 'item_unit_price', 'item_line_total', 'item_price_field_value_id'] as $attribute) {
          if (!empty($params[$attribute]) && !empty($params[$attribute][$i])) {
            if (in_array($attribute, ['item_line_total', 'item_unit_price'])) {
              $notFound = FALSE;
              $params[$attribute][$i] = CRM_Utils_Rule::cleanMoney($params[$attribute][$i]);
            }
            $lineItemParams[$i][str_replace('item_', '', $attribute)] = $params[$attribute][$i];
            if ($attribute == 'item_price_field_value_id') {
              $lineItemParams[$i]['price_field_id'] = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $params[$attribute][$i], 'price_field_id');
            }
          }
        }
        if ($notFound) {
          unset($lineItemParams[$i]);
        }
        else {
          if ($taxEnabled) {
            $lineItemParams[$i]['tax_amount'] = (float) $params['item_tax_amount'][$i] ?? 0.00;
            $params['tax_amount'] += $lineItemParams[$i]['tax_amount'];
          }
          if (!in_array($params['item_financial_type_id'], $financialTypes) && !empty($params['item_financial_type_id'][$i])) {
            $financialTypes[] = $params['item_financial_type_id'][$i];
          }
          $params['total_amount'] = $params['amount'] += ((float) ($lineItemParams[$i]['line_total'] ?? 0.00) + (float) ($lineItemParams[$i]['tax_amount'] ?? 0.00));
          $params['net_amount'] = $params['total_amount'] - $params['fee_amount'] ?? 0;
          if (!empty($lineItemParams[$i]['line_total']) && !empty($lineItemParams[$i]['price_field_id'])) {
            $priceSetID = CRM_Core_DAO::getFieldValue('CRM_Price_BAO_PriceField', $lineItemParams[$i]['price_field_id'], 'price_set_id');
            if (!empty($params['line_item'][$priceSetID])) {
              $params['line_item'][$priceSetID][$lineItemParams[$i]['price_field_id']] = $lineItemParams[$i];
            }
          }
        }
      }
      // If we have line Item financial types. Pick the first one to set the contribution to.
      if (count($financialTypes) > 0) {
        $params['financial_type_id'] = $financialTypes[0];
      }
    }
    elseif ($op == 'edit' && CRM_Core_Permission::check('edit line item')) {
      $newLineItemParams = $lineItemParams = $newLineItem = [];
      for ($i = 0; $i <= 10; $i++) {
        $lineItemParams[$i] = [];
        $notFound = TRUE;
        foreach (['item_label', 'item_financial_type_id', 'item_qty', 'item_unit_price', 'item_line_total', 'item_price_field_value_id', 'item_tax_amount'] as $attribute) {
          if (!empty($params[$attribute]) && !empty($params[$attribute][$i])) {
            if ($attribute == 'item_line_total') {
              $notFound = FALSE;
            }
            $lineItemParams[$i][str_replace('item_', '', $attribute)] = $params[$attribute][$i];
            if ($attribute == 'item_price_field_value_id') {
              $lineItemParams[$i]['price_field_id'] = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $params[$attribute][$i], 'price_field_id');
            }
          }
        }
        if ($notFound) {
          unset($lineItemParams[$i]);
        }
      }

      $newLineItemParams = [];
      foreach ($lineItemParams as $key => $lineItem) {
        if ($lineItem['price_field_value_id'] == 'new') {
          list($lineItem['price_field_id'], $lineItem['price_field_value_id']) = CRM_Lineitemedit_Util::createPriceFieldByContributionID($entityID);
        }
        else {
          $lineItem['price_field_id'] = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $lineItem['price_field_value_id'], 'price_field_id');
        }
        [$lineEntityTable, $lineEntityID] = CRM_Lineitemedit_Util::addEntity(
          $lineItem['price_field_value_id'],
          $entityID,
          $lineItem['qty']
        );
        $newLineItemParams[] = array(
          'entity_table' => $lineEntityTable,
          'entity_id' => $lineEntityID,
          'contribution_id' => $entityID,
          'price_field_id' => $lineItem['price_field_id'],
          'label' => $lineItem['label'],
          'qty' => $lineItem['qty'],
          'unit_price' => CRM_Utils_Rule::cleanMoney($lineItem['unit_price']),
          'line_total' => CRM_Utils_Rule::cleanMoney($lineItem['line_total']),
          'price_field_value_id' => $lineItem['price_field_value_id'],
          'financial_type_id' => $lineItem['financial_type_id'],
          'tax_amount' => $lineItem['tax_amount'] ?? '0.00',
        );
      }

      foreach ($newLineItemParams as $lineItem) {
        $newLineItem[] = civicrm_api3('LineItem', 'create', $lineItem)['id'];
      }

      if (!empty($lineItemParams)) {
        // calculate balance, tax and paidamount later used to adjust transaction
        $updatedAmount = CRM_Price_BAO_LineItem::getLineTotal($entityID);
        $taxAmount = CRM_Lineitemedit_Util::getTaxAmountTotalFromContributionID($entityID);

        // Record adjusted amount by updating contribution info and create necessary financial trxns
        list($trxn, $contriParams) = CRM_Lineitemedit_Util::recordAdjustedAmt(
          $updatedAmount,
          $entityID,
          $taxAmount,
          TRUE, TRUE
        );
        $entityID = (string) $entityID;
        Civi::cache('lineitemEditor')->set($entityID, $contriParams);

        // record financial item on addition of lineitem
        if ($trxn) {
          foreach ($newLineItem as $lineItemID) {
            $lineItem = civicrm_api3('LineItem', 'getsingle', array('id' => $lineItemID));
            CRM_Lineitemedit_Util::insertFinancialItemOnAdd($lineItem, $trxn);
          }
        }
      }

      // we are already processing line-items above so no need to create/update them again via create()
      $params['skipLineItem'] = TRUE;
    }
  }
}

function lineitemedit_civicrm_post($op, $entity, $entityID, &$obj) {
  if ($entity == 'Contribution' && $op == 'edit' && CRM_Core_Permission::check('edit line item')) {
    $entityID = (string) $entityID;
    $contriParams = Civi::cache('lineitemEditor')->get($entityID);
    if (!empty($contriParams)) {
      \CRM_Utils_Hook::pre('edit', 'Contribution', $entityID, $contriParams);
      $obj->copyValues($contriParams);
      $obj->save();
      Civi::cache('lineitemEditor')->delete($entityID);
      \CRM_Utils_Hook::post('edit', 'Contribution', $entityID, $obj);
    }
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function lineitemedit_civicrm_navigationMenu(&$menu) {
  _lineitemedit_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'biz.jmaconsulting.lineitemedit')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _lineitemedit_civix_navigationMenu($menu);
} // */
