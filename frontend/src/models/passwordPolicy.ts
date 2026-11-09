// Reglas de composición de la política de contraseñas, para marcarlas
// mientras la persona escribe. La política completa (contraseñas comunes,
// nombre, correo y nombre de la plataforma) la aplica el backend
// (app/Models/Auth/PasswordPolicy.php y POST /api/auth/password-check):
// estas reglas deben coincidir con las de ese archivo.

export const PASSWORD_MIN_LENGTH = 8;
export const PASSWORD_MAX_LENGTH = 128;

export interface PasswordRule {
  key: 'length' | 'upper' | 'lower' | 'digit' | 'special';
  label: string;
  test: (password: string) => boolean;
}

export const PASSWORD_RULES: PasswordRule[] = [
  {
    key: 'length',
    label: `Entre ${PASSWORD_MIN_LENGTH} y ${PASSWORD_MAX_LENGTH} caracteres`,
    // [...password] cuenta caracteres (no unidades UTF-16), igual que mb_strlen en PHP
    test: password => [...password].length >= PASSWORD_MIN_LENGTH && [...password].length <= PASSWORD_MAX_LENGTH,
  },
  { key: 'upper', label: 'Una letra mayúscula', test: password => /\p{Lu}/u.test(password) },
  { key: 'lower', label: 'Una letra minúscula', test: password => /\p{Ll}/u.test(password) },
  { key: 'digit', label: 'Un número', test: password => /\d/.test(password) },
  { key: 'special', label: 'Un carácter especial (! # * -)', test: password => /[^\p{L}\p{N}]/u.test(password) },
];

/** Verdadero si la contraseña cumple todas las reglas de composición. */
export function meetsPasswordRules(password: string): boolean {
  return PASSWORD_RULES.every(rule => rule.test(password));
}
