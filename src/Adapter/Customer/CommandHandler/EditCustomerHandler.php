<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\PrestaShop\Adapter\Customer\CommandHandler;

use Customer;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use PrestaShop\PrestaShop\Core\Domain\Customer\Command\EditCustomerCommand;
use PrestaShop\PrestaShop\Core\Domain\Customer\CommandHandler\EditCustomerHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Customer\Exception\CustomerDefaultGroupAccessException;
use PrestaShop\PrestaShop\Core\Domain\Customer\Exception\CustomerException;
use PrestaShop\PrestaShop\Core\Domain\Customer\Exception\DuplicateCustomerEmailException;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\RequiredField;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Email;

/**
 * Handles commands which edits given customer with provided data.
 *
 * @internal
 */
final class EditCustomerHandler extends AbstractCustomerHandler implements EditCustomerHandlerInterface
{
    /**
     * @var Hashing
     */
    private $hashing;

    /**
     * @var string Value of legacy _COOKIE_KEY_
     */
    private $legacyCookieKey;

    /**
     * @param Hashing $hashing
     * @param string $legacyCookieKey
     */
    public function __construct(Hashing $hashing, $legacyCookieKey)
    {
        $this->hashing = $hashing;
        $this->legacyCookieKey = $legacyCookieKey;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(EditCustomerCommand $command)
    {
        $customerId = $command->getCustomerId();
        $customer = new Customer($customerId->getValue());

        $this->assertCustomerWasFound($customerId, $customer);

        // If dealing with a registered customer, we need to check if the email does not exist.
        // Two guests with the same email can co-exist, two registered customers can not.
        // This check only runs if the email is getting changed.
        if (!$customer->isGuest()) {
            $this->assertCustomerWithUpdatedEmailDoesNotExist($customer, $command);
        }

        $this->assertCustomerCanAccessDefaultGroup($customer, $command);

        $this->updateCustomerWithCommandData($customer, $command);

        // validateFieldsRequiredDatabase() below is using $_POST
        // to check if required fields are set
        if ($command->isPartnerOffersSubscribed() !== null) {
            $_POST[RequiredField::PARTNER_OFFERS] = $command->isPartnerOffersSubscribed();
        } elseif ($command->isNewsletterSubscribed() !== null) {
            $_POST[RequiredField::NEWSLETTER] = $command->isNewsletterSubscribed();
        }

        // before validation, we need to get the list of customer mandatory fields from the database
        // and set their current values (only if it is not being modified: if it is not in $_POST)
        $requiredFields = $customer->getFieldsRequiredDatabase();
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field['field_name'], $_POST)) {
                $_POST[$field['field_name']] = $customer->{$field['field_name']};
            }
        }

        $this->assertRequiredFieldsAreNotMissing($customer);

        if (false === $customer->validateFields(false)) {
            throw new CustomerException('Customer contains invalid field values');
        }

        if (false === $customer->update()) {
            throw new CustomerException('Failed to update customer');
        }
    }

    /**
     * @param Customer $customer
     * @param EditCustomerCommand $command
     */
    private function updateCustomerWithCommandData(Customer $customer, EditCustomerCommand $command)
    {
        if (null !== $command->getGenderId()) {
            $customer->id_gender = $command->getGenderId();
        }

        if (null !== $command->getFirstName()) {
            $customer->firstname = $command->getFirstName()->getValue();
        }

        if (null !== $command->getLastName()) {
            $customer->lastname = $command->getLastName()->getValue();
        }

        if (null !== $command->getEmail()) {
            $customer->email = $command->getEmail()->getValue();
        }

        if (null !== $command->getPassword()) {
            $hashedPassword = $this->hashing->hash(
                $command->getPassword()->getValue(),
                $this->legacyCookieKey
            );

            $customer->passwd = $hashedPassword;
        }

        if (null !== $command->getBirthday()) {
            $customer->birthday = $command->getBirthday()->getValue();
        }

        if (null !== $command->isEnabled()) {
            $customer->active = $command->isEnabled();
        }

        if (null !== $command->isPartnerOffersSubscribed()) {
            $customer->optin = $command->isPartnerOffersSubscribed();
        }

        if (null !== $command->getGroupIds()) {
            $customer->groupBox = $command->getGroupIds();
        }

        if (null !== $command->getDefaultGroupId()) {
            $customer->id_default_group = $command->getDefaultGroupId();
        }

        if (null !== $command->isNewsletterSubscribed()) {
            $customer->newsletter = $command->isNewsletterSubscribed();
        }

        $this->updateCustomerB2bData($customer, $command);
    }

    /**
     * @param Customer $customer
     * @param EditCustomerCommand $command
     */
    private function updateCustomerB2bData(Customer $customer, EditCustomerCommand $command)
    {
        if (null !== $command->getCompanyName()) {
            $customer->company = $command->getCompanyName();
        }

        if (null !== $command->getSiretCode()) {
            $customer->siret = $command->getSiretCode();
        }

        if (null !== $command->getApeCode()) {
            $customer->ape = $command->getApeCode()->getValue();
        }

        if (null !== $command->getWebsite()) {
            $customer->website = $command->getWebsite();
        }

        if (null !== $command->getAllowedOutstandingAmount()) {
            $customer->outstanding_allow_amount = $command->getAllowedOutstandingAmount();
        }

        if (null !== $command->getMaxPaymentDays()) {
            $customer->max_payment_days = $command->getMaxPaymentDays();
        }

        if (null !== $command->getRiskId()) {
            $customer->id_risk = $command->getRiskId();
        }
    }

    /**
     * @param Customer $customer
     * @param EditCustomerCommand $command
     */
    private function assertCustomerWithUpdatedEmailDoesNotExist(Customer $customer, EditCustomerCommand $command)
    {
        // We only check this if the email is getting changed.
        if (null === $command->getEmail()) {
            return;
        }

        // If the email is getting changed, but is the same as the current email, nothing to do here.
        if ($command->getEmail()->isEqualTo(new Email($customer->email))) {
            return;
        }

        $customerByEmail = new Customer();

        // Now check if a registered customer with the same email exists, ignoring guests.
        $customerByEmail->getByEmail($command->getEmail()->getValue());

        if ($customerByEmail->id) {
            throw new DuplicateCustomerEmailException($command->getEmail(), sprintf('Customer with email "%s" already exists', $command->getEmail()->getValue()));
        }
    }

    /**
     * @param Customer $customer
     * @param EditCustomerCommand $command
     */
    private function assertCustomerCanAccessDefaultGroup(Customer $customer, EditCustomerCommand $command)
    {
        // If nothing is updated on groups, nothing to do here
        if (null === $command->getDefaultGroupId() && null === $command->getGroupIds()) {
            return;
        }

        // Arrange data to compare, we will use customer's original data if not provided in the command
        $groupIds = ($command->getGroupIds() === null ? $customer->getGroups() : $command->getGroupIds());
        $defaultGroupId = ($command->getDefaultGroupId() === null ? $customer->id_default_group : $command->getDefaultGroupId());

        // Check if the default group is in the list of checked groups
        if (!in_array($defaultGroupId, $groupIds)) {
            throw new CustomerDefaultGroupAccessException(sprintf('Customer default group with id "%s" must be in access groups', $command->getDefaultGroupId()));
        }
    }
}
