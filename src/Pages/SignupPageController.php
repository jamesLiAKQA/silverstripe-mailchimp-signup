<?php

namespace Innoweb\MailChimpSignup\Pages;

use DrewM\MailChimp\MailChimp;
use Innoweb\MailChimpSignup\MailchimpDataLoader;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\TextField;
use SilverStripe\SpamProtection\Extension\FormSpamProtectionExtension;
use SilverStripe\View\Requirements;
use BadMethodCallException;
use PageController;

class SignupPageController extends PageController {

    private static $block_default_jquery_and_validate = false;
    private static $block_form_validation = false;

    private static $allowed_actions = [
        'Form',
        'success'
    ];

    public function Form()
    {
        $this->extend('onBeforeSignupForm');

        // Get list data
        $listInfo = $this->getMailchimpMergeFields();

        // create field list and validator
        $fields = FieldList::create();

        // create validator with default email field
        $validator = RequiredFields::create('EMAIL');

        $emailAdded = false;

        if ($listInfo) {
            $fieldData = $listInfo['merge_fields'];

            // sort fields
            $sortedData = $this->sortArray($fieldData, 'display_order', SORT_ASC);

            for ($pos = 1; $pos <= count($sortedData); $pos++) {

                // get field data
                $field = $sortedData[$pos-1];
                $newField = null;

                // check if email needs to be added
                if ($pos != $field['display_order'] && !$emailAdded) {
                    $newField = EmailField::create(
                        'EMAIL',
                        _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.EmailAddress', 'Email Address'),
                        null,
                        255
                    );
                    $fields->push($newField);
                    $emailAdded = true;
                }

                if ($field['public']) {

                    // add field validation
                    if ($field['required']) {
                        $validator->addRequiredField($field['tag']);
                    }

                    // add field
                    switch ($field['type']) {

                        case 'text':
						case 'address':
							if ($field['tag'] == 'EMAIL') {
								$newField = EmailField::create(
									$field['tag'],
									$field['name'],
									$field['default_value'],
									255
								);
								$emailAdded = true;
							} else {
								$newField = TextField::create(
									$field['tag'],
									$field['name'],
									$field['default_value'],
									255
								);
							}
                            break;

                        case 'number':
                            $newField = NumericField::create(
                                $field['tag'],
                                $field['name'],
                                $field['default_value'],
                                255
                            );
                            break;

						case 'date':
                        case 'birthday':
                            $newField = DateField::create(
                                $field['tag'],
                                $field['name'],
                                $field['default_value'],
                                255
                            );
                            break;

                        case 'phone':
                            $newField = TextField::create(
                                $field['tag'],
                                $field['name'],
                                $field['default_value'],
                                255
                            )->setAttribute('type', 'tel');
                            break;

                        case 'url':
                            $newField = TextField::create(
                                $field['tag'],
                                $field['name'],
                                $field['default_value'],
                                255
                            )->setAttribute('type', 'url');
                            break;

                        case 'dropdown':

                            // set value and key to value
                            $optionSet = [];
                            foreach ($field['choices'] as $opt) {
                                $optionSet[$opt] = $opt;
                            }
                            $newField = DropdownField::create(
                                $field['tag'],
                                $field['name'],
                                $optionSet
                            );
                            break;

                        case 'radio':

                            // set value and key to value
                            $optionSet = [];
                            foreach ($fields['choices'] as $opt) {
                                $optionSet[$opt] = $opt;
                            }
                            $newField = OptionsetField::create(
                                $field['tag'],
                                $field['name'],
                                $optionSet
                            );
                            break;

                        default:
                            $newField = LiteralField::create(
                                $field['tag'],
                                'ERROR: UNSUPPORTED FIELDTYPE ('
                                . $field['type']
                                . ') -> '
                                . $field['tag']
                                . ' '
                                . $field['name']
                            );
                    }

                    $fields->push($newField);

                    // add description to field
                    if ($field['help_text']) {
                        $newField->setRightTitle($field['help_text']);
                    }
                }
            }
        } else {
            return false;
        }

        // check again if email needs to be added
        if (!$emailAdded) {
            $newField = EmailField::create(
                'EMAIL',
                _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.EmailAddress', 'Email Address'),
                null,
                255
            );
            $fields->push($newField);
        }

        // add group fields
        $groupInfo = $this->getMailchimpCategories();
        if ($groupInfo) {
            foreach ($groupInfo as $group) {

                // get options
                $options = [];
                $groupOptions = $group['options'];

                if ($groupOptions && isset($groupOptions['interests'])) {
                    $sortedOptions = $this->sortArray($groupOptions['interests'], 'display_order', SORT_ASC);
                    foreach ($sortedOptions as $option) {
                        $options[$option['id']] = $option['name'];
                    }
                }

                // add field
                switch ($group['type']) {
                    case 'radio':
                        $fields->push(
                            OptionsetField::create(
                                'groupings_'.$group['id'],
                                $group['title'],
                                $options
                            )
                        );
                        break;

                    case 'checkboxes':
                        $fields->push(
                            CheckboxSetField::create(
                                'groupings_'.$group['id'],
                                $group['title'],
                                $options
                            )
                        );
                        break;

                    case 'dropdown':
                        $fields->push(
                            DropdownField::create(
                                'groupings_'.$group['id'],
                                $group['title'],
                                $options
                            )
                        );
                        break;

                    case 'hidden':
                        //skip it
                        break;

                    default:
                        $fields->push(
                            LiteralField::create(
                                $group['id'],
                                'ERROR: UNSUPPORTED GROUP TYPE ('
                                . $group['form_field']
                                . ') -> '
                                . $group['id']
                                . ' '
                                . $group['title']
                            )
                        );
                }
            }
        }

        $usesEmailTypeOptions = $this->getUsesEmailTypeOptions();
        if ($usesEmailTypeOptions) {
            $fields->push(
                OptionsetField::create(
                    'EMAILTYPE',
                    _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.PREFERREDFORMAT', 'Preferred Format'),
                    [
                        'html' => _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.FORMATHTML', 'HTML'),
                        'text' => _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.FORMATTEXT', 'Plain-text')
                    ],
                    'html'
                )
            );
            $validator->addRequiredField('EMAILTYPE');
        }

        $actions = FieldList::create(
            FormAction::create('subscribe', _t('Innoweb\\MailChimpSignup\\Model\\SignupPage.SUBSCRIBE', 'Subscribe'))
        );

        $form = Form::create(
            $this,
            'Form',
            $fields,
            $actions,
            $validator
        );

        $form->setHTMLID('mailchimp-signup-form');

        // Retrieve potentially saved data and populate form field if values were present in session variables
        try {
            $session = $this->getRequest()->getSession();
            $sessionData = $session->get('FormInfo.' . $form->FormName() . '.data');
            if (is_array($sessionData)) {
                $form->loadDataFrom($sessionData);
            }
        } catch (BadMethodCallException $e) {
            // no session available
        }

        // load jquery and validate
        if (!$this->config()->get('block_default_jquery_and_validate')) {
            Requirements::javascript('innoweb/silverstripe-mailchimp-signup: client/dist/javascript/jquery.min.js');
            Requirements::javascript('innoweb/silverstripe-mailchimp-signup: client/dist/javascript/jquery-validate.min.js');
        }
        // load validation script
        if (!$this->config()->get('block_form_validation')) {
            Requirements::javascript('innoweb/silverstripe-mailchimp-signup: client/dist/javascript/mailchimp-validation.min.js');
        }

        // enable spam protection if available
        if (class_exists(FormSpamProtectionExtension::class)) {
            $form = $form->enableSpamProtection();
        }

        // Re-initiate the form error set up with the new HTMLID and Spam Protection field (if applies).
        $form->restoreFormState();

        $this->extend('updateSignupForm', $form);

        return $form;
    }

    private function getMailchimpMergeFields()
    {
        if (!$this->APIKey || !$this->ListID) {
            user_error("MailChimp API key or list ID is missing", E_USER_WARNING);
            return false;
        }

        $loader = MailchimpDataLoader::getInstance($this->APIKey, $this->ListID);
        return $loader->getMergeFields();

    }

    private function getMailchimpCategories()
    {
        if (!$this->APIKey || !$this->ListID) {
            user_error("MailChimp API key or list ID is missing", E_USER_WARNING);
            return false;
        }

        $loader = MailchimpDataLoader::getInstance($this->APIKey, $this->ListID);
        return $loader->getCategories();
    }

    private function getUsesEmailTypeOptions()
    {
        if (!$this->APIKey || !$this->ListID) {
            user_error("MailChimp API key or list ID is missing", E_USER_WARNING);
            return false;
        }

        $loader = MailchimpDataLoader::getInstance($this->APIKey, $this->ListID);
        return $loader->getUsesEmailTypeOptions();
    }

    public function subscribe($data, $form)
    {
        $result = $this->doSubscription($data);
        $session = $this->getRequest()->getSession();

        if (isset($result['type']) && $result['type'] == 'good') {

            // clear session data
            $sessionData = $session->get('FormInfo.' . $form->FormName() . '.data');
            if (is_array($sessionData)) {
                $session->clear('FormInfo.' . $form->FormName() . '.data');
            }

            // redirect
            return $this->redirect($this->join_links($this->Link(), 'success'));

        } else {

            // Store the submitted data in case the user needs to try again
            $session->set('FormInfo.' . $form->FormName() . '.data', $data);

            // add error message
            $form->sessionMessage($result['message'], $result['type']);

            // redirect back
            return $this->redirectBack();
        }
    }

    public function success()
    {
        return $this;
    }

    public function doSubscription($data)
    {
        $returnData = [
            'type'      =>  'error',
            'message'   =>  $this->ContentError
        ];

        // initialize
        $mailChimp = new MailChimp($this->APIKey);
        $mailChimp->verify_ssl = Config::inst()->get(MailChimp::class, 'verify_ssl');

        $memberInfo = $mailChimp->get(sprintf(
            'lists/%s/members/%s',
            $this->ListID,
            md5(strtolower($data['EMAIL']))
        ));
        $memberFound = $mailChimp->success();

        if ($memberFound && $memberInfo && isset($memberInfo['status']) && $memberInfo['status'] == 'subscribed') {

            // The e-mail address has already subscribed, provide feedback
            $returnData['type'] = 'warning';
            $returnData['message'] = _t(
                'Innoweb\\MailChimpSignup\\Model\\SignupPage.DUPLICATE',
                'This email address is already subscribed to this list.'
            );

        } else {

            // gather member data for submission

            $mergeVars = [];
            // get list data
            $listInfo = $this->getMailchimpMergeFields();
            if ($listInfo) {
                $fieldData = $listInfo['merge_fields'];
                foreach ($fieldData as $field) {
                    if ($field['public']) {
                        // add value from field
                        if (isset($data[$field['tag']])) {
                            $mergeVars[$field['tag']] = $data[$field['tag']];
                        }
                    }
                }
            }

            // same for groups
            $aGroups = [];
            $groupInfo = $this->getMailchimpCategories();
            if ($groupInfo) {
                foreach ($groupInfo as $group) {

                    // get options
                    $groupOptions = $group['options'];

                    if ($groupOptions && isset($groupOptions['interests'])) {
                        // get submitted data
                        if (is_array($data['groupings_' . $group['id']])) {
                            $submittedGroups = $data['groupings_' . $group['id']];
                        } else {
                            $submittedGroups = [$data['groupings_' . $group['id']]];
                        }
                        // init group array
                        foreach ($groupOptions['interests'] as $option) {
                            if (in_array($option['id'], $submittedGroups)) {
                                $aGroups[$option['id']] = true;
                            } else {
                                $aGroups[$option['id']] = false;
                            }
                        }
                    }
                }
            }

            // build submission data
            $submissionData = [
                'email_address' => $data['EMAIL'],
                'status' => $this->RequireEmailConfirmation ? 'pending' : 'subscribed',
            ];
            if (count($mergeVars)) {
                $submissionData['merge_fields'] = $mergeVars;
            }
            if (count($aGroups)) {
                $submissionData['interests'] = $aGroups;
            }

            if (!$memberFound) {

                // no on list, new subscription

                $mailChimp->post(
                    sprintf(
                        'lists/%s/members',
                        $this->ListID
                    ),
                    $submissionData
                );

            } else {

                // update existing record

                $mailChimp->patch(
                    sprintf(
                        'lists/%s/members/%s',
                        $this->ListID,
                        md5(strtolower($data['EMAIL']))
                    ),
                    $submissionData
                );
            }

            // check if update/adding successful
            if ($mailChimp->success()) {

                // set message
                $returnData['type'] = 'good';
                $returnData['message'] = $this->ContentSuccess;

            } else {
                user_error('Last Error: ' . print_r($mailChimp->getLastError(), true), E_USER_WARNING);
                user_error('Last Request: ' . print_r($mailChimp->getLastRequest(), true), E_USER_WARNING);
                user_error('Last Response: ' . print_r($mailChimp->getLastResponse(), true), E_USER_WARNING);
            }
        }

        return $returnData;
    }

    protected function sortArray()
    {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = [];
                foreach ($data as $key => $row) {
                    $tmp[$key] = $row[$field];
                }
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }
}
