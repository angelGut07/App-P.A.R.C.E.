<?php

namespace Tests\Unit\Core\Http;

use App\Core\Http\RequestValidator;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Tests unitarios de los métodos generales de RequestValidator sin cobertura
 * dedicada previa (isValidEmail, isValidPassword, sanitizeString,
 * validateRequiredFields, validateContentType, parseJsonBody,
 * validateLoginRequest, validateChangePasswordRequest,
 * validateForgotPasswordRequest, validateResetPasswordRequest).
 *
 * validateRegistrationRequest() ya tiene su propio archivo
 * (RequestValidatorRegistrationTest, rama de restricción de `role`) y
 * parsePagination() también (RequestValidatorPaginationTest) — no se repiten
 * aquí. Sin base de datos: RequestValidator no llama a Database:: en ningún
 * método.
 */
class RequestValidatorTest extends TestCase
{
    use SimulatesHttp;

    protected function setUp(): void
    {
        $this->backupSuperglobals();
    }

    protected function tearDown(): void
    {
        $this->restoreSuperglobals();
    }

    // =========================================================================
    // isValidEmail()
    // =========================================================================

    public function testIsValidEmailAcceptsAWellFormedAddress(): void
    {
        $this->assertTrue(RequestValidator::isValidEmail('cliente@parce.test'));
    }

    public function testIsValidEmailRejectsAMalformedAddress(): void
    {
        $this->assertFalse(RequestValidator::isValidEmail('no-es-un-correo'));
    }

    public function testIsValidEmailRejectsAnAddressLongerThan255Characters(): void
    {
        $tooLong = str_repeat('a', 250) . '@x.com';
        $this->assertGreaterThan(255, strlen($tooLong));
        $this->assertFalse(RequestValidator::isValidEmail($tooLong));
    }

    // =========================================================================
    // isValidPassword()
    // =========================================================================

    public function testIsValidPasswordAcceptsAPasswordWithinRange(): void
    {
        $this->assertTrue(RequestValidator::isValidPassword('Password123!'));
    }

    public function testIsValidPasswordRejectsAPasswordShorterThan8Characters(): void
    {
        $this->assertFalse(RequestValidator::isValidPassword('Aa1!'));
    }

    public function testIsValidPasswordRejectsAPasswordLongerThan128Characters(): void
    {
        $this->assertFalse(RequestValidator::isValidPassword(str_repeat('a', 129)));
    }

    public function testIsValidPasswordRejectsAPasswordContainingANullByte(): void
    {
        $this->assertFalse(RequestValidator::isValidPassword("Password1\0"));
    }

    // =========================================================================
    // sanitizeString()
    // =========================================================================

    public function testSanitizeStringTrimsWhitespace(): void
    {
        $this->assertSame('hola', RequestValidator::sanitizeString('  hola  '));
    }

    public function testSanitizeStringRemovesNullBytes(): void
    {
        $this->assertSame('hola', RequestValidator::sanitizeString("ho\0la"));
    }

    // =========================================================================
    // validateRequiredFields()
    // =========================================================================

    public function testValidateRequiredFieldsReturnsTheNamesOfMissingFieldsOnly(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'a@b.com', 'password' => '']);

        $missing = RequestValidator::validateRequiredFields($request, ['email', 'password', 'first_name']);

        $this->assertSame(['password', 'first_name'], $missing);
    }

    public function testValidateRequiredFieldsReturnsAnEmptyArrayWhenAllArePresent(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'a@b.com']);

        $this->assertSame([], RequestValidator::validateRequiredFields($request, ['email']));
    }

    // =========================================================================
    // validateContentType()
    // =========================================================================

    public function testValidateContentTypeSkipsValidationForGetAndOptions(): void
    {
        $request = $this->makeRequest('GET', asJson: false);

        $this->assertTrue(RequestValidator::validateContentType($request, 'GET')['valid']);
        $this->assertTrue(RequestValidator::validateContentType($request, 'OPTIONS')['valid']);
    }

    public function testValidateContentTypeRejectsAMissingHeaderWith415(): void
    {
        $request = $this->makeRequest('POST', asJson: false);

        $result = RequestValidator::validateContentType($request, 'POST');

        $this->assertFalse($result['valid']);
        $this->assertSame(415, $result['statusCode']);
    }

    public function testValidateContentTypeRejectsAWrongTypeWith415(): void
    {
        $request = $this->makeRequest('POST', asJson: false, headers: ['Content-Type' => 'text/plain']);

        $result = RequestValidator::validateContentType($request, 'POST');

        $this->assertFalse($result['valid']);
        $this->assertSame(415, $result['statusCode']);
    }

    public function testValidateContentTypeAcceptsApplicationJsonWithACharsetSuffix(): void
    {
        $request = $this->makeRequest('POST', asJson: false, headers: ['Content-Type' => 'application/json; charset=utf-8']);

        $this->assertTrue(RequestValidator::validateContentType($request, 'POST')['valid']);
    }

    // =========================================================================
    // parseJsonBody()
    // =========================================================================

    public function testParseJsonBodyRejectsAnEmptyPostBody(): void
    {
        $request = $this->makeRequest('POST', rawBody: '');

        $result = RequestValidator::parseJsonBody($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(400, $result['statusCode']);
    }

    public function testParseJsonBodyRejectsMalformedJson(): void
    {
        $request = $this->makeRequest('POST', rawBody: '{not-json');

        $result = RequestValidator::parseJsonBody($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('El cuerpo de la petición no es un JSON válido', $result['error']);
    }

    public function testParseJsonBodyRejectsAJsonArrayInsteadOfAnObject(): void
    {
        $request = $this->makeRequest('POST', rawBody: '[1,2,3]');

        $result = RequestValidator::parseJsonBody($request);

        $this->assertFalse($result['valid']);
        $this->assertSame('El cuerpo de la petición debe ser un objeto JSON', $result['error']);
    }

    public function testParseJsonBodyRejectsABodyLargerThanTheContentLengthLimit(): void
    {
        $request = $this->makeRequest('POST', body: ['x' => 'y'], headers: ['Content-Length' => (string)(1048576 + 1)]);

        $result = RequestValidator::parseJsonBody($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(413, $result['statusCode']);
    }

    public function testParseJsonBodyAcceptsAWellFormedObject(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'a@b.com']);

        $result = RequestValidator::parseJsonBody($request);

        $this->assertTrue($result['valid']);
        $this->assertSame('a@b.com', $result['data']['email']);
    }

    // =========================================================================
    // validateLoginRequest()
    // =========================================================================

    public function testValidateLoginRequestReportsMissingFields(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $result = RequestValidator::validateLoginRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(['email', 'password'], $result['errors']['fields']);
    }

    public function testValidateLoginRequestReportsAnInvalidEmailFormat(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'no-es-un-correo', 'password' => 'Password123!']);

        $result = RequestValidator::validateLoginRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function testValidateLoginRequestSucceedsWithValidFields(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'a@b.com', 'password' => 'Password123!']);

        $this->assertTrue(RequestValidator::validateLoginRequest($request)['valid']);
    }

    // =========================================================================
    // validateChangePasswordRequest()
    // =========================================================================

    public function testValidateChangePasswordRequestReportsAPasswordConfirmationMismatch(): void
    {
        $request = $this->makeRequest('PUT', body: [
            'current_password' => 'Old12345!', 'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'Distinta123!',
        ]);

        $result = RequestValidator::validateChangePasswordRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('new_password_confirmation', $result['errors']);
    }

    public function testValidateChangePasswordRequestSucceedsWithValidFields(): void
    {
        $request = $this->makeRequest('PUT', body: [
            'current_password' => 'Old12345!', 'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $this->assertTrue(RequestValidator::validateChangePasswordRequest($request)['valid']);
    }

    // =========================================================================
    // validateForgotPasswordRequest()
    // =========================================================================

    public function testValidateForgotPasswordRequestRejectsAnInvalidEmail(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'no-es-un-correo']);

        $this->assertFalse(RequestValidator::validateForgotPasswordRequest($request)['valid']);
    }

    public function testValidateForgotPasswordRequestSucceedsWithAValidEmail(): void
    {
        $request = $this->makeRequest('POST', body: ['email' => 'a@b.com']);

        $this->assertTrue(RequestValidator::validateForgotPasswordRequest($request)['valid']);
    }

    // =========================================================================
    // validateResetPasswordRequest()
    // =========================================================================

    public function testValidateResetPasswordRequestReportsMissingFields(): void
    {
        $request = $this->makeRequest('POST', body: []);

        $result = RequestValidator::validateResetPasswordRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(['token', 'new_password', 'new_password_confirmation'], $result['errors']['fields']);
    }

    public function testValidateResetPasswordRequestSucceedsWithValidFields(): void
    {
        $request = $this->makeRequest('POST', body: [
            'token' => 'abc123', 'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $this->assertTrue(RequestValidator::validateResetPasswordRequest($request)['valid']);
    }

    public function testIsValidPhoneAcceptsOnlyDigitsWithOptionalPlusSpacesAndHyphens(): void
    {
        $this->assertTrue(RequestValidator::isValidPhone('3001234567'));
        $this->assertTrue(RequestValidator::isValidPhone('+57 300 123 4567'));
        $this->assertTrue(RequestValidator::isValidPhone('601-555-1234'));

        $this->assertFalse(RequestValidator::isValidPhone('300abc4567'));
        $this->assertFalse(RequestValidator::isValidPhone('telefono'));
        $this->assertFalse(RequestValidator::isValidPhone('12345'));
        $this->assertFalse(RequestValidator::isValidPhone('1234567890123456'));
    }
}
