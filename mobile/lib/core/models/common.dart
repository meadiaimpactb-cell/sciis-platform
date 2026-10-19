// Shared JSON helpers and small value objects.
//
// Models are hand-written `fromJson` (no generated *.g.dart) so the skeleton
// compiles without running build_runner. json_serializable is declared in
// pubspec and can be adopted per model later; the shapes below follow the
// API Resources in SPEC §4–§6 and docs/openapi.yaml.

DateTime? parseDate(Object? v) {
  if (v == null) return null;
  if (v is DateTime) return v;
  return DateTime.tryParse(v.toString());
}

int? parseInt(Object? v) {
  if (v == null) return null;
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v.toString());
}

double? parseDouble(Object? v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  return double.tryParse(v.toString());
}

bool parseBool(Object? v, {bool fallback = false}) {
  if (v == null) return fallback;
  if (v is bool) return v;
  final s = v.toString().toLowerCase();
  return s == '1' || s == 'true';
}

List<Map<String, dynamic>> listOfMaps(Object? v) {
  if (v is List) {
    return v.whereType<Map<String, dynamic>>().toList(growable: false);
  }
  return const [];
}

List<String> listOfStrings(Object? v) {
  if (v is List) return v.map((e) => e.toString()).toList(growable: false);
  return const [];
}

Map<String, dynamic> asMap(Object? v) =>
    v is Map<String, dynamic> ? v : const <String, dynamic>{};

/// A taxonomy term as returned inline on resources (`{key, label}`), the label
/// already localised server-side according to Accept-Language (FRM-04).
class TaxonomyTerm {
  const TaxonomyTerm({required this.key, required this.label, this.group});

  factory TaxonomyTerm.fromJson(Map<String, dynamic> json) => TaxonomyTerm(
        key: (json['key'] ?? '').toString(),
        label: (json['label'] ?? json['label_ar'] ?? json['key'] ?? '').toString(),
        group: json['group'] as String?,
      );

  final String key;
  final String label;
  final String? group;

  static List<TaxonomyTerm> listFromJson(Object? v) =>
      listOfMaps(v).map(TaxonomyTerm.fromJson).toList(growable: false);
}

/// Laravel paginator envelope: `{data: [...], meta: {current_page, last_page, per_page, total}}`.
class Paginated<T> {
  const Paginated({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory Paginated.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic>) itemFromJson,
  ) {
    final meta = asMap(json['meta']);
    return Paginated<T>(
      items: listOfMaps(json['data']).map(itemFromJson).toList(growable: false),
      currentPage: parseInt(meta['current_page']) ?? 1,
      lastPage: parseInt(meta['last_page']) ?? 1,
      total: parseInt(meta['total']) ?? 0,
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;
}
