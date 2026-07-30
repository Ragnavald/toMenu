/**
 * Formata telefone/WhatsApp com máscara brasileira:
 * (11) 99999-9999 (11 dígitos) ou (11) 3333-4444 (10 dígitos).
 */
export function formatPhone(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 11);
  if (digits.length === 0) return '';
  if (digits.length <= 2) return `(${digits}`;
  if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  if (digits.length <= 10) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  }
  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
}

/**
 * Formata CEP no padrão 00000-000 (8 dígitos max).
 */
export function formatCep(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 8);
  if (digits.length <= 5) return digits;
  return `${digits.slice(0, 5)}-${digits.slice(5)}`;
}

export interface ViaCepResponse {
  street: string;
  district: string;
  city: string;
  state: string;
}

/**
 * Busca endereço no ViaCEP se o CEP tiver 8 dígitos numéricos.
 */
export async function fetchViaCep(cep: string): Promise<ViaCepResponse | null> {
  const cleanZip = cep.replace(/\D/g, '');
  if (cleanZip.length !== 8) return null;

  try {
    const response = await fetch(`https://viacep.com.br/ws/${cleanZip}/json/`);
    if (!response.ok) return null;
    const data = await response.json();
    if (data.erro) return null;

    return {
      street: data.logradouro || '',
      district: data.bairro || '',
      city: data.localidade || '',
      state: data.uf || '',
    };
  } catch {
    return null;
  }
}

export interface AddressFields {
  zip: string;
  street: string;
  number: string;
  complement: string;
  district: string;
  city: string;
  state: string;
}

/**
 * Monta uma string formatada a partir dos campos estruturados de endereço.
 */
export function buildAddressString(fields: AddressFields): string {
  const line1Parts = [];
  if (fields.street.trim()) line1Parts.push(fields.street.trim());
  if (fields.number.trim()) line1Parts.push(`nº ${fields.number.trim()}`);
  if (fields.complement.trim()) line1Parts.push(fields.complement.trim());

  const line1 = line1Parts.join(', ');

  const line2Parts = [];
  if (fields.district.trim()) line2Parts.push(fields.district.trim());
  const cityState = [fields.city.trim(), fields.state.trim().toUpperCase()]
    .filter(Boolean)
    .join('/');
  if (cityState) line2Parts.push(cityState);

  const line2 = line2Parts.join(' — ');

  let result = [line1, line2].filter(Boolean).join(' — ');
  if (fields.zip.trim()) {
    result = result
      ? `${result} (CEP: ${fields.zip.trim()})`
      : `CEP: ${fields.zip.trim()}`;
  }

  return result;
}

/**
 * Converte uma string de endereço existente para campos estruturados.
 */
export function parseAddressString(addressStr: string): AddressFields {
  if (!addressStr) {
    return {
      zip: '',
      street: '',
      number: '',
      complement: '',
      district: '',
      city: '',
      state: '',
    };
  }

  let zip = '';
  let text = addressStr.trim();

  const zipMatch =
    text.match(/\(CEP:\s*([\d-]+)\)/i) || text.match(/CEP:\s*([\d-]+)/i);
  if (zipMatch) {
    zip = zipMatch[1];
    text = text.replace(zipMatch[0], '').replace(/[\s—,]+$/, '').trim();
  }

  return {
    zip: formatCep(zip),
    street: text,
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
  };
}
