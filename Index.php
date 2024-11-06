<?php

/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Jellyfish\ContactBoost\Controller\Index;

//namespace SR\ReCaptcha\Plugin\Contact\Controller\Index;

use Jellyfish\ContactBoost\Model\ContactFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Contact\Model\ConfigInterface;
use Magento\Contact\Model\MailInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\ScopeInterface;
use Jellyfish\ContactBoost\Helper\Data as ContactHelper;
use Magento\Framework\Controller\ResultFactory;

class Post extends \Magento\Contact\Controller\Index\Post
{

    /**
     * @var DataPersistorInterface
     */
    private $dataPersistor;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var MailInterface
     */
    private $mail;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \Magento\Framework\Translate\Inline\StateInterface
     */
    protected $inlineTranslation;

    /**
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    protected $_transportBuilder;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * contactFactory
     *
     * @var Jellyfish\ContactBoost\Model\ContactFactory
     */
    protected $_contactFactory;

    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $formKeyValidator;

    /**
     * @var \Magento\Framework\Stdlib\CookieManagerInterface
     */
    protected $cookie;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected $_curl;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
      #@+
     * Salesforce contact us URL
     */
    const SALESFORCE_URL = "jellyfish/jellyfish_plugin/salesforce_contact_us";

    /**
      #@+
     * Salesforce contact us Track ID
     */
    const SALESFORCE_GATRACKID = "jellyfish/jellyfish_plugin/salesforce_contact_gatrackid";

    // #@-
    /**
     * @var \Jellyfish\Activ\Helper\Data
     */
    private $activityHelper;

    /**
     * @var \SR\ReCaptcha\Helper\Data
     */
    protected $dataHelper;

    /**
     * @var \Jellyfish\ContactBoost\Helper\Data
     */
    protected $contactHelper;

     /**
     * $resultFactory
     *
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;


    const XML_PATH_EMAIL_TEMPLATE_FIELD = 'contact/email/email_template';
    const SENDER_NAME = 'jellyfish/email_setting/sender_name';
    const SENDER_EMAIL = 'jellyfish/email_setting/sender_email';

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context              $context
     * @param \Magento\Framework\Mail\Template\TransportBuilder  $transportBuilder
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Store\Model\StoreManagerInterface         $storeManager
     * @param \SR\ReCaptcha\Helper\Data                          $dataHelper
     * @param ContactFactory                                     $contactFactory
     * @param ContactHelper                                      $contactHelper
     * @param \Magento\Framework\Controller\ResultFactory        $resultFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\HTTP\Client\Curl $curl,
        ContactFactory $contactFactory,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        \Jellyfish\ActivityTracker\Helper\Data $activityHelper,
        \Mageplaza\GoogleRecaptcha\Helper\Data $dataHelper,
        ConfigInterface $contactsConfig,
        MailInterface $mail,
        DataPersistorInterface $dataPersistor,
        \Jellyfish\General\Helper\Data $generalHelper,
        LoggerInterface $logger = null,
        ContactHelper $contactHelper,
        ResultFactory $resultFactory
    ) {
        $this->_contactFactory = $contactFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->cookie = $cookieManager;
        $this->_curl = $curl;
        $this->_storeManager = $storeManager;
        $this->activityHelper = $activityHelper;
        $this->dataHelper = $dataHelper;
        $this->mail = $mail;
        $this->dataPersistor = $dataPersistor;
        $this->logger = $logger ? : ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->inlineTranslation = $inlineTranslation;
        $this->scopeConfig = $scopeConfig;
        $this->_transportBuilder = $transportBuilder;
        $this->_generalHelper = $generalHelper;
        $this->contactHelper = $contactHelper;
        $this->resultFactory = $resultFactory;
        parent::__construct($context, $contactsConfig, $mail, $dataPersistor, $logger);
    }

//end __construct()

    /**
     * Post user question
     *
     * @return void
     * @throws \Exception
     */
    public function execute()
    {
        $post = $this->getRequest()->getPostValue();
        $response = [];
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        if (!$post) {
            $this->activityHelper->track('Contact Us: Save', 'No data received', 0, 1, $post);
            $this->_redirect($this->_redirect->getRefererUrl());
        }
        if (!$this->formKeyValidator->validate($this->getRequest())) {
            $this->activityHelper->track('Contact Us: Save', 'Form key validation failure', 0, 1, $post);
            $this->_redirect($this->_redirect->getRefererUrl());
        }

        $noOfPeople = $this->contactHelper->getNoOfPeople();
        $plannedBudget = $this->contactHelper->getPlannedBudget(); 
        $categoryNames = $this->contactHelper->getCategoryNamesOptionsArray();
        if ($this->dataHelper->isEnabled()) {
            try {
                $postObject = new \Magento\Framework\DataObject();

                $error = false;

                if (!\Zend_Validate::is(trim($post['name']), 'NotEmpty')) {
                    $response = ['status' =>'validation', 'message' => 'Name is required field'];
                    $resultJson->setData($response);
                    return $resultJson;
                }


                if (!\Zend_Validate::is(trim($post['email']), 'EmailAddress')) {
                    $response = ['status' =>'validation', 'message' => 'Please enter valid email address'];
                     $resultJson->setData($response);
                    return $resultJson;
                }

                if (!\Zend_Validate::is(trim($post['no_of_people']), 'NotEmpty')) {
                    $response = ['status' =>'validation', 'message' => 'No of People is required field'];
                    $resultJson->setData($response);
                    return $resultJson;
                }

                if(!(count($noOfPeople) > 0 && in_array($post['no_of_people'], $noOfPeople))) {
                    $response = ['status' =>'validation', 'message' => 'Please pass correct value for No Of People dropdown.'];
                    $resultJson->setData($response);
                    return $resultJson;
                }
                
                if (!\Zend_Validate::is($post['interested_in'], 'NotEmpty')) {
                    $response = ['status' =>'validation', 'message' => 'Interested In is required field'];
                    $resultJson->setData($response);
                    return $resultJson;
                }

                if (!(count($categoryNames) > 0 && empty(array_diff($post['interested_in'], $categoryNames)))) {
                    $response = ['status' =>'validation', 'message' => 'Please pass correct values for Interested In dropdown.'];
                    $resultJson->setData($response);
                    return $resultJson;
                }
                
                if (!\Zend_Validate::is(trim($post['planned_budget']), 'NotEmpty')) {
                    $response = ['status' =>'validation', 'message' => 'Planned Budget is required field'];
                    $resultJson->setData($response);
                    return $resultJson;
                }

                if(!(count($plannedBudget) > 0 && in_array($post['planned_budget'], $plannedBudget))) {
                    $response = ['status' =>'validation', 'message' => 'Please pass correct value for Planned Budget dropdown.'];
                    $resultJson->setData($response);
                    return $resultJson;
                }

                if (\Zend_Validate::is(trim($post['hideit']), 'NotEmpty')) {
                    $response = ['status' =>'validation', 'message' => 'Hide It is required field'];
                    $resultJson->setData($response);
                    return $resultJson;
                }

                //salesforce code
                $salesforce = [];
                $name = explode(" ", trim($post['name']));
                $title = $post['title'] ?? '';
                if (count($name) > 1) {
                    $salesforce['firstname'] = $title != '' ? $title .' '. $name[0] : $name[0];
                    unset($name[0]);
                    $salesforce['lastname'] = implode(" ", $name);
                } else {
                    $salesforce['firstname'] = $title;
                    $salesforce['lastname'] = $name[0];
                }
                // Use regular expression to separate currency symbol and value
                preg_match('/^([^\d]+)(\d.*)$/', $post['planned_budget'], $matches);
                $currencySymbol = '';
                $budgetValue = '';
                if(isset($matches) && !empty($matches)) {
                    $currencySymbol = $matches[1];
                    $budgetValue = $matches[2];
                }
                $post['name'] = $title != '' ? $title .' '. $post['name'] : $post['name'];
                $post['interested_in'] = implode(",", $post['interested_in']);
                $websiteName = $this->_generalHelper->getCurrentWebsiteName();
                $interestedIn = $post['interested_in'];
                $salesforce['Email'] = $post['email'];
                $salesforce['Phone'] = $post['telephone'];
                $salesforce['Company'] = empty(trim($post['company'])) ? "Not Provided" : $post['company'];
                $salesforce['ImInterestedin'] = $interestedIn;
                $salesforce['noofpeople'] = $post['no_of_people'];
                $salesforce['plannedbudgetcurrency'] = $currencySymbol;
                $salesforce['plannedbudget'] = $budgetValue;
                $salesforce['message'] = $interestedIn != '' ? $post['comment'] . ' Interested in services: ' . str_replace(';', ',', $interestedIn) : $post['comment'];
                $salesforce['infinity_visitor_id'] = $post['inf_vid'];
                $salesforce['infinity_installation_id'] = $post['inf_igrp'];
                $salesforce['GACookieId'] = $this->_fetch_cid_value();
                $salesforce['Country'] = $websiteName;
                $salesforce['country_full'] = $websiteName;
                $salesforce['RecordType'] = '012j00000019THy';
                $salesforce['CAMPAIGNID'] = 'Jellyfish Training';
                $salesforce['LeadSource'] = 'Website Inbound';
                $salesforce['SubSource'] = 'Jellyfish Training - Form';
                $salesforce['GATRACKID__c'] = $post['GATRACKID'];
                $salesforce['GACLIENTID__c'] = $post['GACLIENTID'];
                $salesforce['page_url'] = $post['page_url'];
                $salesforce['page_type'] = $post['page_type'];
                
                if (array_key_exists("is_subscribed", $post)) {
                    $salesforce['JFCommunications'] = "True";
                } else {
                    $salesforce['JFCommunications'] = "False";
                }

                if (trim($this->_generalHelper->getSystemConfigValue(self::SALESFORCE_GATRACKID))) {
                    $salesforce['GACLIENTID'] = $this->_fetch_cid_value();
                    $salesforce['GATRACKID'] = $this->_generalHelper->getSystemConfigValue(self::SALESFORCE_GATRACKID);
                }

                $post['link'] = $this->generateLink($post);
                $postObject->setData($post);

                $this->notify($post['name'],$post['email']);

                $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
                if (!empty($this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, $storeScope)) && trim($this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, $storeScope)) != '') {
                    $transport = $this->_transportBuilder
                            ->setTemplateIdentifier($this->scopeConfig->getValue(self::XML_PATH_EMAIL_TEMPLATE, $storeScope))
                            ->setTemplateOptions(
                                [
                                        'area' => \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE,
                                        'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
                                    ]
                            )
                            ->setTemplateVars(['data' => $postObject])
                            ->setFrom($this->scopeConfig->getValue(self::XML_PATH_EMAIL_SENDER, $storeScope))
                            ->addTo($this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT, $storeScope))
                            ->setReplyTo($post['email'])
                            ->getTransport();

//                    $transport->sendMessage();
                    $this->activityHelper->track('Contact Us: Save', 'Email notification sent successfully', 1, 1);
                }
                $this->inlineTranslation->resume();
                /*
                 *
                 * save contact info
                 */
                $contact = $this->_contactFactory->create();

                $post['created_at'] = (strtotime('now'));
                $post['is_subscribed'] = isset($post['is_subscribed']) ? 1 : 0;
                $post['salesforce_json'] = json_encode($salesforce, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $post['is_salesforce_submitted'] = 0;
                $post['ref_website'] = $this->_storeManager->getStore()->getWebsiteId();
                $contact->setData($post)->save();

                // $this->messageManager->addSuccess(
                //     __('Thank you for your enquiry, we will be in contact shortly.')
                // );
                $response = ['status'=>'success'];
                $this->activityHelper->track('Contact Us: Save', 'Details saved successfully', 1, 2);
                $this->getDataPersistor()->clear('contact_us');
              
              //  $this->sendResponse($response);
               // $this->_redirect($this->_redirect->getRefererUrl());
               // return;
            } catch (\Exception $e) {
                $this->inlineTranslation->resume();
                // $this->messageManager->addError(
                //     __('We can\'t process your request right now. Sorry, that\'s all we know.')
                // );
                $response = ['status' =>'exception', 'message' => 'Exception:'. $e->getMessage()];
                $this->activityHelper->track('Contact Us: Save', 'Exception: ' . $e->getMessage(), 0, 3, $post);
                $this->getDataPersistor()->set('contact_us', $post);
                //$this->_redirect($this->_redirect->getRefererUrl());
            }//end try
            $resultJson->setData($response);
            return $resultJson;
        }
    }


    /**
     * @param $variable
     * @param $receiverInfo
     * @param $templateId
     * @return $this
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function generateTemplate($variable, $receiverInfo, $templateId)
    {
        $senderInfo = [
                    'name'  => $this->getSenderName(),
                    'email' => $this->getSenderEmail(),
                ];
        $this->_transportBuilder->setTemplateIdentifier($templateId)
            ->setTemplateOptions(
                [
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $this->_storeManager->getStore()->getId(),
                ]
            )
            ->setTemplateVars($variable)
            ->setFrom($senderInfo)
            ->addTo($receiverInfo['email'], $receiverInfo['name']);

        return $this;
    }

    /**
     * @param $name
     * @param $email
     * @return $this
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function notify($name, $email)
    {
        /* Receiver Detail */
        $receiverInfo = [
            'name' => $name,
            'email' => $email
        ];

        /* Assign values for your template variables  */
        $variable = [];
        $variable['varaibleName'] = 'some information';

        $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_TEMPLATE_FIELD, $this->_storeManager->getStore()->getId());
        $this->inlineTranslation->suspend();
        $this->generateTemplate($variable, $receiverInfo, $templateId);
        $transport = $this->_transportBuilder->getTransport();
        $transport->sendMessage();
        $this->inlineTranslation->resume();

        return $this;
    }

    /**
     * Get sender email
     *
     * @return type
     */
    public function getSenderEmail()
    {
        return $this->getConfigValue(self::SENDER_EMAIL, $this->_storeManager->getStore()->getId());
    }

    /**
     * Get Sender Name
     *
     * @return string
     */
    public function getSenderName()
    {
        return $this->getConfigValue(self::SENDER_NAME, $this->_storeManager->getStore()->getId());
    }
     /**
     * Return store configuration value of your template field that which id you set for template
     *
     * @param string $path
     * @param int $storeId
     * @return mixed
     */
    private function getConfigValue($path, $storeId)
    {
        return $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

//end execute()

    /**
     * Get Data Persistor
     *
     * @return DataPersistorInterface
     */
    private function getDataPersistor()
    {
        if ($this->dataPersistor === null) {
            $this->dataPersistor = ObjectManager::getInstance()
                    ->get(DataPersistorInterface::class);
        }

        return $this->dataPersistor;
    }

//end getDataPersistor()

    /**
     * Generate salesforce link
     *
     * @return string
     */
    public function generateLink($post)
    {
        $firstName = '';
        $lastName = '';
        $parts = explode(" ", $post['name']);
        if (count($parts) == 1) {
            $firstName = rawurlencode($parts[0]);
            $lastName = 'Not Provided';
        } else {
            $lastName = array_pop($parts);
            $firstName = rawurlencode(implode(" ", $parts));
        }

        $email = rawurlencode($post['email']);
        $phone = rawurlencode($post['telephone']);
        $company = ($post['company']) ? rawurlencode($post['company']) : "Not Provided";
        $postComment = $post['comment'] ? rawurlencode($post['comment']) : '';
        $postInterestedIn = $post['interested_in'] ? rawurlencode($post['interested_in']) : '' ;
        $messagetext = $postComment . rawurlencode('Interested in services: ') . $postInterestedIn;

        $time = rawurlencode(date('d/m/Y H:i', time()));

        $inf_vid = $post['inf_vid'];
        $inf_igrp = $post['inf_igrp'];
        $gaCookieId = $this->_fetch_cid_value();
        $signUpForCommunication = isset($post['is_subscribed']) ? true : false;
        $salesForceBaseUrl = 'https://emea.salesforce.com/00Q/e?retURL=%2F00Q%2Fo&00Nj0000009CXCj=';
        $salesforceLink1 = '&name_firstlea2=' . $firstName . '&name_lastlea2=' . $lastName . '&lea3=' . $company . '&lea11=' . $email;
        $salesforceLink2 = '&lea8=' . $phone . '&lea17=' . $messagetext . '&00N20000009Iuuu=' . $time . '&00Nj0000009CXCn=' . $inf_vid . '&00N0a00000CN93B=' . $gaCookieId;
        $sales_force_link = $salesForceBaseUrl . $inf_igrp . $salesforceLink1 . $salesforceLink2 . '&00N0m000000dL9L=' . $signUpForCommunication;

        $link = '<a href="' . $sales_force_link . '">' . $sales_force_link . '</a>';
        return $sales_force_link;
    }

//end generateLink()

    /**
     * @return \Magento\Framework\Data\Form\FormKey\Validator
     * @deprecated
     */
    private function getFormKeyValidator()
    {
        if (!$this->formKeyValidator) {
            $this->formKeyValidator = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Magento\Framework\Data\Form\FormKey\Validator::class);
        }
        return $this->formKeyValidator;
    }

    /**
     * Returns "$crm_val" from "_ga" cookie value
     * For example if "_ga" cookie has value : GA1.2.170423272.1417796975
     * "_fetch_cid_value" function will return : 170423272.1417796975
     */
    public function _fetch_cid_value()
    {
        $crm_val = ''; // our output to be used later on

        if ($this->cookie->getCookie('_ga')) {
            $cookie_val = $this->cookie->getCookie('_ga'); // get the value of the cookie we're looking for
            $cookie_chunks = explode('.', $cookie_val); // explode the value by "." character

            $reformed_chunks = null; // we're going to use this as a holding array later on

            /**
             * we want to ignore the first 2 parts of the value
             * (dunno why, that's what's been asked for by analytics...)
             */
            $numChunks = count($cookie_chunks);
            if (count($cookie_chunks) > 2) {
                for ($counter = 2; $counter < $numChunks; $counter ++) {
                    $reformed_chunks[] = $cookie_chunks[$counter];
                }
            }

            /**
             * Doing this test as if we don't add anything to this var then it will
             * still be null and not an array
             */
            if (!is_null($reformed_chunks)) {
                $crm_val = implode('.', $reformed_chunks);
            }
        }

        return $crm_val;
    }

     /**
     * Returns JSON response
     * @param array $response
     * 
     */
    public function sendResponse($response) 
    {
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $resultJson->setData($response);
        return $resultJson;
    }
}

//end class
