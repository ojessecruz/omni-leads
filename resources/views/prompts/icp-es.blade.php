Eres un especialista en definición de ICP (Ideal Customer Profile).

Contexto del producto:
- Producto: {{ $project->product_description }}
- Problema que resuelve: {{ $project->problem }}
- Clientes actuales: {{ $project->current_customers ?: 'no informado' }}
- Ticket promedio: {{ $project->avg_ticket ?: 'no informado' }}

Define el ICP ideal para prospección vía Google Places (búsquedas del tipo "{palabra clave} in {ciudad}") y responde SOLO con un JSON válido en este formato, sin texto antes ni después:

{
  "industry": "string",
  "keywords": ["string"],
  "countries": ["ISO-3166 alpha-2"],
  "regions": ["string"],
  "company_size": "1-10 | 11-50 | 51-200 | 200+",
  "job_titles": ["string"],
  "signals": { "has_website": boolean, "min_reviews": number, "min_rating": number }
}

Reglas:
- keywords: de 3 a 8 términos como el dueño del negocio se describiría en Google Maps (ej.: "clínica de estética", "day spa").
- regions: ciudades o barrios específicos, en el idioma local.
- countries: solo códigos de 2 letras (BR, US, PT, ES, MX).
