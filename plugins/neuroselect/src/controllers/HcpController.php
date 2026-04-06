<?php
namespace neuroscience\neuroselect\controllers;

use enupal\stripe\Stripe as StripePlugin;
use neuroscience\neuroselect\Neuroselect;

use Craft;
use craft\web\Controller;
use craft\elements\User;
use craft\mail\Message;
use craft\web\View;
use craft\mail\Mailer;
use craft\elements\Entry;
use craft\commerce\models\Discount;
use craft\commerce\records\Discount as DiscountRecord;
use craft\commerce\Plugin as CommercePlugin;

use enupal\stripe\elements\Order as StripeOrder;

use Stripe\Subscription;
use enupal\stripe\Stripe;
use Yii;
use yii\db\Query;

class HcpController extends Controller
{
    // protected $allowAnonymous = ['new-patient'];
    protected $allowAnonymous = true;

    public function actionSavePatient()
    {
        $this->requirePostRequest();
        $firstName = Craft::$app->request->getBodyParam('firstName');
        $lastName = Craft::$app->request->getBodyParam('lastName');
        $email = Craft::$app->request->getBodyParam('email');
        $isAjax = Craft::$app->request->isAjax;
        $existingUser = Craft::$app->users->getUserByUsernameOrEmail($email);

        if( $existingUser == null )
        {
            $sendRecommendation = Craft::$app->request->getBodyParam('sendRecommendation');
            $user = new User();
            $user->email      = $email;
            $user->username   = $email;
            $user->firstName  = $firstName;
            $user->lastName   = $lastName;
            $user->password   = Yii::$app->security->generatePasswordHash($email);
            // $user->pending    = true;
            // $user->unverifiedEmail = '';
            $hcpUser = Craft::$app->getUser()->getIdentity();
            
            // default to customerservice@neurorelief.com
            if (!$hcpUser) {
              $hcpUser = \craft\elements\User::find()
                ->email('customerservice@neurorelief.com')
                ->one();
            }
            
            $user->setFieldValues([
                'relatedHcp' => [$hcpUser->id]
            ]);
            $success = $user->validate(null, false) && Craft::$app->getElements()->saveElement($user, false);
            if (!$success) {
                $userMsg = ['success' => 0, 'message' => 'Patient not saved due to validation error.'];
                if (!$user->hasErrors('email')) {
                    $userMsg = ['success' => 0, 'message' => $user->getErrors('unverifiedEmail')];
                }
                if( $isAjax ){
                    return $this->asJson($userMsg);
                }
            }

            Craft::$app->users->assignUserToGroups($user->id, [6]);
            // Craft::$app->getUsers()->sendActivationEmail($user);

            // Invite Email to Patient
            $passwordResetUrl = Craft::$app->getUsers()->getPasswordResetUrl($user);
            $hcpUser = Craft::$app->getUser()->getIdentity();
            
            // default to customerservice@neurorelief.com
            if (!$hcpUser) {
              $hcpUser = \craft\elements\User::find()
                ->email('customerservice@neurorelief.com')
                ->one();
            }
            
            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/invite-patient', 
                ['passwordResetUrl' => $passwordResetUrl, 'patient' => $user, 'hcp' => $hcpUser]
            );

            if( $hcpUser->hcpStorefrontName ){
                $hcpStorefrontName = $hcpUser->hcpStorefrontName;
            }else{
                if( $hcpUser->firstName ){
                    $hcpStorefrontName = $hcpUser->firstName . ' ' . $hcpUser->lastName;
                }else{
                    $hcpStorefrontName = $hcpUser->username;
                }
            }

            $subject = "Invitation from " . $hcpStorefrontName;
            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($user->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $mailer->send($message);

            if( $isAjax ){
                return $this->asJson([
                    'success' => 1,
                    'message' => ''
                ]);
            }

            if( $sendRecommendation ){
                return $this->redirect("hcp/recommendation?patientId=" . $user->id);
            }else{
                return $this->redirectToPostedUrl();
            }
        }
        else
        {
            $userMsg = ['success' => 0, 'message' => 'Patient account cannot be created. Email address already exists.'];
            if( $isAjax ){
                return $this->asJson($userMsg);
            }
            Craft::$app->session->setFlash('error', 'Patient account cannot be created. Email address already exists.');
            return $this->redirect("hcp/new-patient");            
        }
    }

    public function actionSaveRecommendation()
    {
        $this->requirePostRequest();
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $recommendedProducts = Craft::$app->request->getBodyParam('recommendedProducts');
        $patientId = Craft::$app->request->getBodyParam('patientId');
        $recommendationNote = Craft::$app->request->getBodyParam('recommendationNote');

        $isAjax = Craft::$app->request->isAjax;

        if( isset($recommendedProducts) && count($recommendedProducts) > 0 ){
            $entry = new Entry();
            $entry->sectionId = 21;
            $entry->typeId = 22;
            $entry->authorId = $hcpUser->id;
            $entry->enabled = true;
            $entry->title = time();
            $entry->setFieldValues([
                'patientAccount' => [$patientId],
                'recommendedProducts' => $recommendedProducts,
                'relatedHcp' => [$hcpUser->id],
                'recommendationNote' => $recommendationNote
            ]);
            $success = Craft::$app->elements->saveElement($entry);
            if (!$success) {
                Craft::error('Couldn’t save the entry "'.$entry->title.'"', __METHOD__);
            }

            // Sending email to patient about this recommendation
            $patientUser = Craft::$app->users->getUserById($patientId);
            $products = \craft\commerce\elements\Product::find()->id($recommendedProducts)->orderBy('title ASC')->all();
            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/recommendations', 
                ['products' => $products, 'patient' => $patientUser, 'hcp' => $hcpUser, 'recommendationNote' => $recommendationNote]
            );
			
            $subject = $patientUser->firstName . ", view your provider’s recommendation";

            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($patientUser->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $mailer->send($message);

            if( $isAjax ){
                return $this->asJson([
                    'success' => 1,
                    'message' => ''
                ]);
            }

        }
        return $this->redirectToPostedUrl();
    }

    // public function actionSaveProductSelections()
    // {
    //     $this->requirePostRequest();
    //     $hcpUser = Craft::$app->getUser()->getIdentity();
    //     $recommendedProducts = Craft::$app->request->getBodyParam('recommendedProducts');
    //     $hcpUser->setFieldValues(['recommendedProducts' => $recommendedProducts]);
    //     $result = Craft::$app->getElements()->saveElement($hcpUser);
    //     return $this->redirectToPostedUrl();        
    // }

    // re-send-recommendation
    public function actionReSendRecommendation()
    {
        $recId = Craft::$app->request->getQueryParam('recId');
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $recEntry = \craft\elements\Entry::find()->id($recId)->relatedTo($hcpUser)->one();
        if( $recEntry ){
            $patientUser = $recEntry->patientAccount->anyStatus()->one();
            $products = $recEntry->recommendedProducts->all();
            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/recommendations', 
                ['products' => $products, 'patient' => $patientUser, 'hcp' => $hcpUser, 'recommendationNote' => $recEntry->recommendationNote]
            );
            // Sending email to patient about this recommendation
            $subject = $patientUser->firstName . ", view your provider’s recommendation";
            $emailBody = '';
            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($patientUser->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $mailer->send($message);
            return $this->redirect('hcp/patient?id=' . $patientUser->id);
        }else{
            return $this->redirect('404');
        }
    }

    // remove-recommendation
    public function actionRemoveRecommendation()
    {
        $recId = Craft::$app->request->getQueryParam('recId');
        $patientId = Craft::$app->request->getQueryParam('patientId');
        if( $recId && $patientId ){
            $patientUser = Craft::$app->users->getUserById($patientId);
            $hcpUser = Craft::$app->getUser()->getIdentity();            
            $recEntry = \craft\elements\Entry::find()->id($recId)->relatedTo($hcpUser)->relatedTo($patientUser)->one();
            if( $recEntry ){
                Craft::$app->getElements()->deleteElementById($recId);
                return $this->redirect('hcp/patient?id=' . $patientId);
            }
        }
        return $this->redirect('404');
    }

    // save-patient-notes
    public function actionSavePatientNotes()
    {
        $this->requirePostRequest();
        $patientId = Craft::$app->request->getBodyParam('patientId');
        $patientNotes = Craft::$app->request->getBodyParam('patientNotes');
        $patientUser = Craft::$app->users->getUserById($patientId);
        $patientUser->setFieldValues([
            'patientNotes' => $patientNotes
        ]);
        $success = Craft::$app->elements->saveElement($patientUser);
        return $success;
    }

    // HCP Accept Terms & Conditions
    public function actionAcceptTermsConditions()
    {
        $this->requirePostRequest();
        $accpetedTermsAndConditions = Craft::$app->request->getBodyParam('accpetedTermsAndConditions');
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $hcpUser->setFieldValues([
            'accpetedTermsAndConditions' => $accpetedTermsAndConditions
        ]);
        $success = Craft::$app->elements->saveElement($hcpUser);

        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        $body = Craft::$app->getView()->renderTemplate(
            'hcp/_emails/accept-tc', 
            ['hcp' => $hcpUser]
        );

        $mailer = Craft::$app->getMailer();
        $message = new Message();
        $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
        $message->setTo(array(0 => "doug.O'Hara@neurorelief.com", 1 => 'amanda.sobottka@neurorelief.com'));
        $message->setSubject("Accept Terms & Conditions");
        $message->setHtmlBody($body);
        $mailer->send($message);

        return $this->redirectToPostedUrl();
    }

    // Save patient NeuroCash discount
    public function actionSavePatientDiscount()
    {
        $this->requirePostRequest();

        $patientId = Craft::$app->request->getBodyParam('patientId', false);

        if( ! $patientId ) return $this->redirectToPostedUrl();
        $patientUser = Craft::$app->users->getUserById($patientId);

        if( is_null($patientUser) ) return $this->redirectToPostedUrl();
        $percentDiscount = Craft::$app->request->getBodyParam('percentDiscount', false);
        if( ! $percentDiscount ) return $this->redirectToPostedUrl();

        // Remove any other discount for this patient
        $query = new Query();
        $res = $query->select(['id', 'orderConditionFormula'])->from('craft_commerce_discounts')->where(['like', 'orderConditionFormula', '%'. $patientUser->email . '%', false]);
        foreach ($res->all() as $row) {
            $id = $row['id'];
            $orderConditionFormula = str_replace($patientUser->email, 'customerservice@neurorelief.com', $row['orderConditionFormula']);
            Craft::$app->db->createCommand()->update('craft_commerce_discounts', ['orderConditionFormula' => $orderConditionFormula], ['id' => $id])->execute();
        }

        $this->_saveHcpDiscount();

        return $this->redirectToPostedUrl();
    }

    // neuroselect/hcp/save-discount-sharing
    public function actionSaveDiscountSharing()
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;
        $hcpSharingDiscountName = $request->getBodyParam('name', false);
        $hcpSharingDiscountDetail = $request->getBodyParam('description', false);
        $hcpSharingDiscount = $request->getBodyParam('percentDiscount', 0);
        $currentUser = Craft::$app->getUser()->getIdentity();
        if( ($currentUser->isInGroup('physicians') || Craft::$app->getUser()->getIsAdmin()) && $hcpSharingDiscountName && $hcpSharingDiscount > 0 ){
            $hcpNeurocashPercentage = $currentUser->hcpNeurocashPercentage;
            if( $hcpSharingDiscount <= $hcpNeurocashPercentage ){
                $currentUser->setFieldValues([
                    'hcpSharingDiscountName' => $hcpSharingDiscountName,
                    'hcpSharingDiscount' => $hcpSharingDiscount,
                    'hcpSharingDiscountDetail' => $hcpSharingDiscountDetail
                ]);
                $success = Craft::$app->elements->saveElement($currentUser);
                if( !$success ){
                    Craft::$app->session->setFlash('error', 'There is some error in saving discount.');
                }
            }else{
                Craft::$app->session->setFlash('error', 'Sharing discount could not be great than HCP Neurocash Discount.');
            }
        }else{
            Craft::$app->session->setFlash('error', 'Only HCP can create discount.');
        }
        return $this->redirect('hcp/dashboard');
    }

    public function actionSaveDiscount()
    {
        $this->requirePostRequest();
        $this->_saveHcpDiscount();
        return $this->redirect('hcp/dashboard');
    }

    // Create new discount 
    private function _saveHcpDiscount()
    {
        $request = Craft::$app->getRequest();

        $currentUser = Craft::$app->getUser()->getIdentity();
        $patientNeurocashDiscount = $currentUser->patientNeurocashDiscount;
        if( ! $patientNeurocashDiscount ) {
            $this->setFailFlash(Craft::t('commerce', 'Couldn’t save discount.'));
            return $this->redirect('hcp/dashboard');
        }

        $percentDiscountAmount = $request->getBodyParam('percentDiscount', false);

        if( !$percentDiscountAmount || $percentDiscountAmount > $patientNeurocashDiscount ) {
            $this->setFailFlash(Craft::t('commerce', 'Couldn’t save discount.'));
            return $this->redirect('hcp/dashboard');
        }

        $patientId = Craft::$app->request->getBodyParam('patientId', false);
        $patients = [];
        
        if( $patientId ){
            $patientUser = Craft::$app->users->getUserById($patientId);
            $name = $percentDiscountAmount . '% discount for ' . $patientUser->fullName;
            $patients = [$patientUser->email];
        }else{
            $name = $request->getBodyParam('name', '');

            $hcpPatients = craft\elements\User::find()->groupId(6)->relatedTo(['targetElement' => $currentUser, 'field' => 'relatedHcp'])->all();
            foreach ($hcpPatients as $hcpPatient) {
                array_push($patients, $hcpPatient->email);
            }
        }

        if( empty($name) ) return $this->redirect('hcp/dashboard');

        $discount = new Discount();

        $discount->name = $name;
        $discount->description = $request->getBodyParam('description', '');
        $discount->enabled = 1;
        $discount->code = null;

        $discount->allPurchasables = 1;
        $discount->allCategories = 1;

        // $recommendationProductIds = array_unique($recommendationProductIds);
        $recommendationProductIds = [];
        $patients = array_unique($patients);
        $patientEmails = "'" . implode ( "', '", $patients ) . "'";

        $discount->setPurchasableIds($recommendationProductIds);
        $discount->setCategoryIds([]);
        $discount->categoryRelationshipType = 'element';
        $discount->dateFrom = null;
        $discount->dateTo = null;
        $discount->orderConditionFormula = "order.email in [$patientEmails]";
        $discount->setUserGroupIds([6]);
        $discount->userGroupsCondition = 'userGroupsIncludeAll';
        $discount->purchaseTotal = 0;
        $discount->purchaseQty = 0;
        $discount->maxPurchaseQty = 0;
        $discount->perUserLimit = 0;
        $discount->perEmailLimit = 0;
        $discount->totalDiscountUseLimit = 0;
        $discount->excludeOnSale = true;
        $discount->appliedTo = DiscountRecord::APPLIED_TO_MATCHING_LINE_ITEMS;
        $discount->perItemDiscount = 0;
        $discount->percentDiscount = (float)$percentDiscountAmount / -100;
        $discount->percentageOffSubject = 'original';
        $discount->ignoreSales = true;
        $discount->hasFreeShippingForOrder = false;
        $discount->hasFreeShippingForMatchingItems = false;
        $discount->stopProcessing = false;
        $discount->baseDiscount = 0;
        $discount->baseDiscountType = DiscountRecord::BASE_DISCOUNT_TYPE_VALUE;

        if (CommercePlugin::getInstance()->getDiscounts()->saveDiscount($discount)
        ) {
            $this->setSuccessFlash(Craft::t('commerce', 'Discount saved.'));
        } else {
            $this->setFailFlash(Craft::t('commerce', 'Couldn’t save discount.'));
        }
    }

    // Enable / Disable Autopay for patients
    public function actionEnableAutopay()
    {
        $enabled = Craft::$app->request->getQueryParam('enabled');
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $hcpUser->setFieldValues([
            'enableAutopayForPatients' => $enabled
        ]);
        $success = Craft::$app->elements->saveElement($hcpUser);
        return $success;
    }

    // Set products which will not be available for patient
    public function actionSetProductsAvailability()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        //$availableProducts = $request->getBodyParam('hiddenAvailableProducts');
        $availableProducts = $request->getBodyParam('availableProducts');
        $patientId = $request->getBodyParam('patientId');
        if( empty($patientId) ){
            return $this->redirectToPostedUrl();
        }

        if( empty($availableProducts) ){
            $availableProducts = [];
        }        

        $isHcpPatient = $this->_isHcpPatient($patientId);
        if( ! $isHcpPatient )
        {
            return $this->redirect('hcp/dashboard');
        }

        // $availableProductsArr = explode(",", $availableProducts);
        $patientUser = Craft::$app->users->getUserById($patientId);

        $patientUser->setFieldValues([
            'restrictedProducts' => $availableProducts
        ]);
        $success = Craft::$app->elements->saveElement($patientUser);

        return $this->redirectToPostedUrl();
    }

    private function _isHcpPatient( $patientId )
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        $count = craft\elements\User::find()->id($patientId)->relatedTo(['targetElement' => $currentUser, 'field' => 'relatedHcp'])->anyStatus()->count();
        if( $count ) return true; else return false;
    }

    public function actionDelayAutoship()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $subscriptionId = $request->getBodyParam('subscriptionId');
        $delayAutoshipDate = $request->getBodyParam('delayAutoshipDate');
/*
        $date = date("Y-m-d", strtotime($delayAutoshipDate));
        $sql = "SELECT id from craft_enupalstripe_orders WHERE stripeTransactionId = '{$subscriptionId}'";
        $order = Craft::$app->db->createCommand($sql)->queryOne();
        if($order){
            $orderId = $order['id'];
            $settings = Stripe::$app->settings->getSettings();
            $result = Stripe::$app->subscriptions->cancelStripeSubscription($subscriptionId, $settings->cancelAtPeriodEnd);
            $sql = "INSERT INTO craft_autoship_delay (`orderId`, `dateDelay`) VALUES ($orderId, '{$date}')";
            $success = Craft::$app->db->createCommand($sql)->execute();
        }
*/
		
		$timestamp = strtotime($delayAutoshipDate);

        StripePlugin::$app->settings->initializeStripe();
        Subscription::update($subscriptionId, ['trial_end' => $timestamp, 'proration_behavior' => 'none']);
        
        return $this->redirectToPostedUrl();
    }

    public function actionTest(){

    }



}