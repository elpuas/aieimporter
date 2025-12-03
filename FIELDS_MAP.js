Use the following Excel-to-ACF mapping EXACTLY AS WRITTEN.

TOP-LEVEL FIELDS
    NOMBRE_ALBUM (L) -> title
    INTERPRETE (C)   -> nombre_del_artista_solista_o_agrupacion
    YEAR_PUBLI (Q)   -> ano_de_publicacion
    ID_ALBUM (M)     -> numero_album_o_single

SONG REPEATER
    acf_repeater: nombre_de_cada_tema_del_album_o_sencillo
    fields:
        TITULO (B)      -> titulo_de_la_cancion
        ISRC (N)        -> isrc
        BMAT (E)        -> bmat
        DURACION (R)    -> duracion_en_segundos
        NUMERO_OBRA (A) -> codigo_de_obra

NESTED REPEATER
    acf_repeater: interpretes_y_ejecutantes
    fields:
        NOMBRE (G)      -> nombre_completo
        INSTRUMENTO (K) -> instrumento
        IDROL (I)       -> role

USER LINKING
    source_excel_column: CEDULA (H)
    lookup_method: find_user_by_cedula
    acf_target_field: id_de_usuario
    notes: Do NOT change how user linking works. Always use cédula to find the WP user ID. If no match, leave empty and log a warning.
