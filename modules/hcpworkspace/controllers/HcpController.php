<?php

namespace modules\hcpworkspace\controllers;

use enupal\stripe\Stripe as StripePlugin;
use Craft;
use craft\web\Controller;
use craft\elements\User;
use craft\mail\Message;
use craft\web\View;
use craft\elements\Entry;
use craft\commerce\models\Discount;
use craft\commerce\records\Discount as DiscountRecord;
use craft\commerce\Plugin as CommercePlugin;
use Stripe\Subscription;
use Yii;
use yii\db\Query;

class HcpController extends Controller
{
    protected bool|int|array $allowAnonymous = true;

    public function actionSavePatient()
    {
        $this->requirePostRequest();
        $firstName = Craft::$app->request->getBodyParam('firstName');
        $lastName = Craft::$app->request->getBodyParam('lastName');
        $email = Craft::$app->request->getBodyParam('email');
        $isAjax = Craft::$app->request->isAjax;
        $existingUser = Craft::$app->users->getUserByUsernameOrEmail($email);

        if ($existingUser == null) {
            $sendRecommendation = Craft::$app->request->getBodyParam('sendRecommendation');
            $user = new User();
            $user->email = $email;
            $user->username = $email;
            $user->firstName = $firstName;
            $user->lastName = $lastName;
            $user->password = Yii::$app->security->generatePasswordHash($email);
            $hcpUser = Craft::$app->getUser()->getIdentity();

            if (!$hcpUser) {
                $hcpUser = User::find()
                    ->email('customerservice@neurorelief.com')
                    ->one();
            }

            $user->setFieldValues([
                'relatedHcp' => [$hcpUser->id],
            ]);
            $success = $user->validate(null, false) && Craft::$app->getElements()->saveElement($user, false);
            if (!$success) {
                $userMsg = ['success' => 0, 'message' => 'Patient not saved due to validation error.'];
                if (!$user->hasErrors('email')) {
                    $userMsg = ['success' => 0, 'message' => $user->getErrors('unverifiedEmail')];
                }
                if ($isAjax) {
                    return $this->asJson($userMsg);
                }
            }

            Craft::$app->users->assignUserToGroups($user->id, [6]);

            $passwordResetUrl = Craft::$app->getUsers()->getPasswordResetUrl($user);
            $hcpUser = Craft::$app->getUser()->getIdentity();

            if (!$hcpUser) {
                $hcpUser = User::find()
                    ->email('customerservice@neurorelief.com')
                    ->one();
            }

            Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/invite-patient',
                ['passwordResetUrl' => $passwordResetUrl, 'patient' => $user, 'hcp' => $hcpUser]
            );

            if ($hcpUser->hcpStorefrontName) {
                $hcpStorefrontName = $hcpUser->hcpStorefrontName;
            } else {
                if ($hcpUser->firstName) {
                    $hcpStorefrontName = $hcpUser->firstName . ' ' . $hcpUser->lastName;
                } else {
                    $hcpStorefrontName = $hcpUser->username;
                }
            }

            $subject = 'Invitation from ' . $hcpStorefrontName;
            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($user->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $mailer->send($message);

            if ($isAjax) {
                return $this->asJson([
                    'success' => 1,
                    'message' => '',
                ]);
            }

            if ($sendRecommendation) {
                return $this->redirect('hcp/recommendation?patientId=' . $user->id);
            }

            return $this->redirectToPostedUrl();
        }

        $userMsg = ['success' => 0, 'message' => 'Patient account cannot be created. Email address already exists.'];
        if ($isAjax) {
            return $this->asJson($userMsg);
        }
        Craft::$app->session->setFlash('error', 'Patient account cannot be created. Email address already exists.');

        return $this->redirect('hcp/new-patient');
    }

    public function actionSaveRecommendation()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        // jQuery dataType:'json' sets Accept: application/json but some stacks strip X-Requested-With; without JSON
        // response the client parses redirected HTML and shows a false "email failed" error.
        $wantsJson = $request->getIsAjax() || $request->getAcceptsJson();

        $hcpUser = Craft::$app->getUser()->getIdentity();
        $recommendedProducts = $request->getBodyParam('recommendedProducts');
        $patientId = $request->getBodyParam('patientId');
        $recommendationNote = $request->getBodyParam('recommendationNote');

        if (!$hcpUser) {
            if ($wantsJson) {
                return $this->asJson(['success' => 0, 'message' => 'Not authenticated.']);
            }

            return $this->redirectToPostedUrl();
        }

        if (!isset($recommendedProducts) || count($recommendedProducts) === 0) {
            if ($wantsJson) {
                return $this->asJson(['success' => 0, 'message' => 'Select at least one product.']);
            }

            return $this->redirectToPostedUrl();
        }

        $patientUser = Craft::$app->users->getUserById($patientId);
        if (!$patientUser || !$patientUser->email) {
            Craft::error('save-recommendation: missing patient or email for patientId ' . (string) $patientId, __METHOD__);
            if ($wantsJson) {
                return $this->asJson(['success' => 0, 'message' => 'Patient not found.']);
            }

            return $this->redirectToPostedUrl();
        }

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
            'recommendationNote' => $recommendationNote,
        ]);
        $success = Craft::$app->elements->saveElement($entry);
        if (!$success) {
            Craft::error('Couldn’t save the entry "' . $entry->title . '"', __METHOD__);
            if ($wantsJson) {
                return $this->asJson(['success' => 0, 'message' => 'Could not save recommendation.']);
            }

            return $this->redirectToPostedUrl();
        }

        $products = \craft\commerce\elements\Product::find()->id($recommendedProducts)->orderBy('title ASC')->all();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/recommendations',
                ['products' => $products, 'patient' => $patientUser, 'hcp' => $hcpUser, 'recommendationNote' => $recommendationNote]
            );

            $subject = $patientUser->firstName . ', view your provider’s recommendation';

            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($patientUser->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $sent = $mailer->send($message);
            if (!$sent) {
                Craft::error('save-recommendation: mailer->send returned false for patient ' . $patientUser->email, __METHOD__);
                if ($wantsJson) {
                    return $this->asJson(['success' => 0, 'message' => 'Email could not be sent.']);
                }

                return $this->redirectToPostedUrl();
            }
        } catch (\Throwable $e) {
            Craft::error('save-recommendation: ' . $e->getMessage(), __METHOD__);
            if ($wantsJson) {
                return $this->asJson(['success' => 0, 'message' => 'Email could not be sent.']);
            }

            return $this->redirectToPostedUrl();
        }

        if ($wantsJson) {
            return $this->asJson([
                'success' => 1,
                'message' => '',
            ]);
        }

        return $this->redirectToPostedUrl();
    }

    public function actionReSendRecommendation()
    {
        $recId = Craft::$app->request->getQueryParam('recId');
        $hcpUser = Craft::$app->getUser()->getIdentity();

        if (!$hcpUser) {
            Craft::$app->session->setFlash('error', 'You must be signed in to resend a recommendation.');

            return $this->redirect('login');
        }

        $recEntry = Entry::find()->id($recId)->relatedTo($hcpUser)->one();
        if (!$recEntry) {
            Craft::$app->session->setFlash('error', 'That recommendation could not be found.');

            return $this->redirect('hcp/dashboard');
        }

        $patientUser = $recEntry->patientAccount->anyStatus()->one();
        if (!$patientUser || !$patientUser->email) {
            Craft::error('re-send-recommendation: missing patient or email for recId ' . (string) $recId, __METHOD__);
            Craft::$app->session->setFlash('error', 'Patient email is missing; the recommendation could not be resent.');

            return $this->redirect('hcp/dashboard');
        }

        $products = $recEntry->recommendedProducts->all();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $body = Craft::$app->getView()->renderTemplate(
                'hcp/_emails/recommendations',
                ['products' => $products, 'patient' => $patientUser, 'hcp' => $hcpUser, 'recommendationNote' => $recEntry->recommendationNote]
            );
            $subject = $patientUser->firstName . ', view your provider’s recommendation';
            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($patientUser->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $sent = $mailer->send($message);
            if (!$sent) {
                Craft::error('re-send-recommendation: mailer->send returned false for patient ' . $patientUser->email, __METHOD__);
                Craft::$app->session->setFlash('error', 'The email could not be sent. Please try again.');

                return $this->redirect('hcp/patient?id=' . $patientUser->id);
            }
        } catch (\Throwable $e) {
            Craft::error('re-send-recommendation: ' . $e->getMessage(), __METHOD__);
            Craft::$app->session->setFlash('error', 'The email could not be sent. Please try again.');

            return $this->redirect('hcp/patient?id=' . $patientUser->id);
        }

        Craft::$app->session->setFlash('notice', 'The recommendation email was sent again to your patient.');

        return $this->redirect('hcp/patient?id=' . $patientUser->id);
    }

    public function actionRemoveRecommendation()
    {
        $recId = Craft::$app->request->getQueryParam('recId');
        $patientId = Craft::$app->request->getQueryParam('patientId');
        if ($recId && $patientId) {
            $patientUser = Craft::$app->users->getUserById($patientId);
            $hcpUser = Craft::$app->getUser()->getIdentity();
            $recEntry = Entry::find()->id($recId)->relatedTo($hcpUser)->relatedTo($patientUser)->one();
            if ($recEntry) {
                Craft::$app->getElements()->deleteElementById($recId);

                return $this->redirect('hcp/patient?id=' . $patientId);
            }
        }

        return $this->redirect('404');
    }

    public function actionSavePatientNotes()
    {
        $this->requirePostRequest();
        $patientId = Craft::$app->request->getBodyParam('patientId');
        $patientNotes = Craft::$app->request->getBodyParam('patientNotes');
        $patientUser = Craft::$app->users->getUserById($patientId);
        $patientUser->setFieldValues([
            'patientNotes' => $patientNotes,
        ]);
        $success = Craft::$app->elements->saveElement($patientUser);

        return $success;
    }

    public function actionAcceptTermsConditions()
    {
        $this->requirePostRequest();
        $accpetedTermsAndConditions = Craft::$app->request->getBodyParam('accpetedTermsAndConditions');
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $hcpUser->setFieldValues([
            'accpetedTermsAndConditions' => $accpetedTermsAndConditions,
        ]);
        Craft::$app->elements->saveElement($hcpUser);

        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        $body = Craft::$app->getView()->renderTemplate(
            'hcp/_emails/accept-tc',
            ['hcp' => $hcpUser]
        );

        $mailer = Craft::$app->getMailer();
        $message = new Message();
        $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
        $message->setTo([0 => "doug.O'Hara@neurorelief.com", 1 => 'amanda.sobottka@neurorelief.com']);
        $message->setSubject('Accept Terms & Conditions');
        $message->setHtmlBody($body);
        $mailer->send($message);

        return $this->redirectToPostedUrl();
    }

    public function actionSavePatientDiscount()
    {
        $this->requirePostRequest();

        $patientId = Craft::$app->request->getBodyParam('patientId', false);

        if (!$patientId) {
            return $this->redirectToPostedUrl();
        }
        $patientUser = Craft::$app->users->getUserById($patientId);

        if (is_null($patientUser)) {
            return $this->redirectToPostedUrl();
        }
        $percentDiscount = Craft::$app->request->getBodyParam('percentDiscount', false);
        if (!$percentDiscount) {
            return $this->redirectToPostedUrl();
        }

        $discountsTable = Craft::$app->db->tablePrefix . 'commerce_discounts';
        $query = new Query();
        $res = $query->select(['id', 'orderConditionFormula'])->from($discountsTable)->where(['like', 'orderConditionFormula', '%' . $patientUser->email . '%', false]);
        foreach ($res->all() as $row) {
            $id = $row['id'];
            $orderConditionFormula = str_replace($patientUser->email, 'customerservice@neurorelief.com', $row['orderConditionFormula']);
            Craft::$app->db->createCommand()->update($discountsTable, ['orderConditionFormula' => $orderConditionFormula], ['id' => $id])->execute();
        }

        $this->_saveHcpDiscount();

        return $this->redirectToPostedUrl();
    }

    public function actionSaveDiscountSharing()
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;
        $hcpSharingDiscountName = $request->getBodyParam('name', false);
        $hcpSharingDiscountDetail = $request->getBodyParam('description', false);
        $hcpSharingDiscount = $request->getBodyParam('percentDiscount', 0);
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (($currentUser->isInGroup('physicians') || Craft::$app->getUser()->getIsAdmin()) && $hcpSharingDiscountName && $hcpSharingDiscount > 0) {
            $hcpNeurocashPercentage = $currentUser->hcpNeurocashPercentage;
            if ($hcpSharingDiscount <= $hcpNeurocashPercentage) {
                $currentUser->setFieldValues([
                    'hcpSharingDiscountName' => $hcpSharingDiscountName,
                    'hcpSharingDiscount' => $hcpSharingDiscount,
                    'hcpSharingDiscountDetail' => $hcpSharingDiscountDetail,
                ]);
                $success = Craft::$app->elements->saveElement($currentUser);
                if (!$success) {
                    Craft::$app->session->setFlash('error', 'There was an error saving the discount.');
                }
            } else {
                Craft::$app->session->setFlash('error', 'Sharing discount cannot be greater than your HCP Neurocash discount.');
            }
        } else {
            Craft::$app->session->setFlash('error', 'Only an HCP can create this discount.');
        }

        return $this->redirect('hcp/dashboard');
    }

    public function actionSaveDiscount()
    {
        $this->requirePostRequest();
        $this->_saveHcpDiscount();

        return $this->redirect('hcp/dashboard');
    }

    private function _saveHcpDiscount()
    {
        $request = Craft::$app->getRequest();

        $currentUser = Craft::$app->getUser()->getIdentity();
        $patientNeurocashDiscount = $currentUser->patientNeurocashDiscount;
        if (!$patientNeurocashDiscount) {
            $this->setFailFlash(Craft::t('commerce', 'Couldn’t save discount.'));

            return $this->redirect('hcp/dashboard');
        }

        $percentDiscountAmount = $request->getBodyParam('percentDiscount', false);

        if (!$percentDiscountAmount || $percentDiscountAmount > $patientNeurocashDiscount) {
            $this->setFailFlash(Craft::t('commerce', 'Couldn’t save discount.'));

            return $this->redirect('hcp/dashboard');
        }

        $patientId = Craft::$app->request->getBodyParam('patientId', false);
        $patients = [];

        if ($patientId) {
            $patientUser = Craft::$app->users->getUserById($patientId);
            $name = $percentDiscountAmount . '% discount for ' . $patientUser->fullName;
            $patients = [$patientUser->email];
        } else {
            $name = $request->getBodyParam('name', '');

            $hcpPatients = User::find()->groupId(6)->relatedTo(['targetElement' => $currentUser, 'field' => 'relatedHcp'])->all();
            foreach ($hcpPatients as $hcpPatient) {
                array_push($patients, $hcpPatient->email);
            }
        }

        if (empty($name)) {
            return $this->redirect('hcp/dashboard');
        }

        $discount = new Discount();

        $discount->name = $name;
        $discount->description = $request->getBodyParam('description', '');
        $discount->enabled = 1;
        $discount->code = null;

        $discount->allPurchasables = 1;
        $discount->allCategories = 1;

        $recommendationProductIds = [];
        $patients = array_unique($patients);
        $patientEmails = "'" . implode("', '", $patients) . "'";

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

    public function actionEnableAutopay()
    {
        $enabled = Craft::$app->request->getQueryParam('enabled');
        $hcpUser = Craft::$app->getUser()->getIdentity();
        $hcpUser->setFieldValues([
            'enableAutopayForPatients' => $enabled,
        ]);
        $success = Craft::$app->elements->saveElement($hcpUser);

        return $success;
    }

    public function actionSetProductsAvailability()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $availableProducts = $request->getBodyParam('availableProducts');
        $patientId = $request->getBodyParam('patientId');
        if (empty($patientId)) {
            return $this->redirectToPostedUrl();
        }

        if (empty($availableProducts)) {
            $availableProducts = [];
        }

        $isHcpPatient = $this->_isHcpPatient($patientId);
        if (!$isHcpPatient) {
            return $this->redirect('hcp/dashboard');
        }

        $patientUser = Craft::$app->users->getUserById($patientId);

        $patientUser->setFieldValues([
            'restrictedProducts' => $availableProducts,
        ]);
        Craft::$app->elements->saveElement($patientUser);

        return $this->redirectToPostedUrl();
    }

    private function _isHcpPatient($patientId)
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        $count = User::find()->id($patientId)->relatedTo(['targetElement' => $currentUser, 'field' => 'relatedHcp'])->anyStatus()->count();
        if ($count) {
            return true;
        }

        return false;
    }

    public function actionDelayAutoship()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $subscriptionId = $request->getBodyParam('subscriptionId');
        $delayAutoshipDate = $request->getBodyParam('delayAutoshipDate');

        $timestamp = strtotime($delayAutoshipDate);

        StripePlugin::$app->settings->initializeStripe();
        Subscription::update($subscriptionId, ['trial_end' => $timestamp, 'proration_behavior' => 'none']);

        return $this->redirectToPostedUrl();
    }

    public function actionTest()
    {
    }
}
