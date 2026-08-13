<?php
declare(strict_types=1);

namespace App;

defined('EVENTOS_TIC') || exit;

/**
 * Listas de referencia del formulario.
 *
 * Están en código y no en tablas a propósito: cambian una vez al año, no las
 * administra nadie desde la interfaz, y tenerlas aquí evita cuatro consultas
 * en la pantalla que más carga recibe —el preregistro público—.
 *
 * Si algún día hace falta la división político-administrativa completa del
 * DANE, entra como tabla y esta clase se convierte en su cargador.
 */
final class Datos
{
    /** Municipios por departamento, priorizando la zona de influencia. */
    public const MUNICIPIOS = [
        'Nariño' => [
            'Pasto', 'Ipiales', 'Tumaco', 'Túquerres', 'La Unión', 'Sandoná', 'Samaniego',
            'Barbacoas', 'Cumbal', 'El Charco', 'Guachucal', 'La Cruz', 'Leiva', 'Linares',
            'Policarpa', 'Ricaurte', 'Taminango', 'Consacá', 'Buesaco', 'Yacuanquer',
            'Chachagüí', 'El Tambo', 'Guaitarilla', 'Iles', 'Imués', 'La Florida',
            'Nariño', 'Ospina', 'Potosí', 'Providencia', 'Puerres', 'Pupiales',
            'San Bernardo', 'San Lorenzo', 'San Pablo', 'Santacruz', 'Sapuyes',
            'Tangua', 'Túquerres', 'Yacuanquer',
        ],
        'Putumayo' => ['Mocoa', 'Puerto Asís', 'Orito', 'Villagarzón', 'Sibundoy', 'Valle del Guamuez', 'San Miguel'],
        'Cauca' => ['Popayán', 'Santander de Quilichao', 'Puerto Tejada', 'Silvia', 'Bolívar', 'Patía'],
        'Valle del Cauca' => ['Cali', 'Palmira', 'Buenaventura', 'Tuluá', 'Jamundí'],
        'Bogotá D.C.' => ['Bogotá D.C.'],
        'Otro' => ['Otro municipio'],
    ];

    public const CATEGORIAS = [
        'Gobierno digital y servicios ciudadanos',
        'Conectividad e infraestructura',
        'Inteligencia artificial y datos',
        'Ciberseguridad',
        'Emprendimiento y startups TIC',
        'Educación y talento digital',
        'Desarrollo de software',
        'Soluciones comerciales y empresariales',
        'Innovación pública y gobierno abierto',
        'Economía creativa y contenidos digitales',
    ];

    public const RANGOS_EDAD = ['14–17', '18–25', '26–35', '36–45', '46–60', '60+'];

    public const GENEROS = [
        ''   => 'Prefiero no responder',
        'F'  => 'Femenino',
        'M'  => 'Masculino',
        'NB' => 'No binario',
        'O'  => 'Otro',
    ];

    public const ETNIAS = [
        'Ninguno'        => 'Ninguno',
        'Indígena'       => 'Indígena',
        'Afrocolombiano' => 'Negro, afrocolombiano o afrodescendiente',
        'Raizal'         => 'Raizal',
        'Palenquero'     => 'Palenquero',
        'Rrom'           => 'Rrom / gitano',
    ];

    public const DISCAPACIDADES = [
        'No'        => 'No',
        'Física'    => 'Física o motora',
        'Visual'    => 'Visual',
        'Auditiva'  => 'Auditiva',
        'Cognitiva' => 'Cognitiva o intelectual',
        'Múltiple'  => 'Múltiple',
    ];

    public const TIPOS_DOCUMENTO = [
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'TI' => 'Tarjeta de identidad',
        'PP' => 'Pasaporte',
    ];

    public static function departamentos(): array
    {
        return array_keys(self::MUNICIPIOS);
    }

    public static function municipiosDe(string $departamento): array
    {
        $lista = self::MUNICIPIOS[$departamento] ?? [];
        $lista = array_values(array_unique($lista));
        sort($lista, SORT_LOCALE_STRING);
        return $lista;
    }

    /** ¿Es un municipio válido para ese departamento? Se valida en el servidor. */
    public static function municipioValido(string $departamento, string $municipio): bool
    {
        if ($departamento === '' && $municipio === '') {
            return true;
        }
        return in_array($municipio, self::MUNICIPIOS[$departamento] ?? [], true);
    }
}
