// Investor-side opportunity endpoints (SPEC §6 "Investor").

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api_client.dart';
import '../../core/enums.dart';
import '../../core/models/common.dart';
import '../../core/models/interest.dart';
import '../../core/models/opportunity.dart';

class OpportunityFilters {
  const OpportunityFilters({
    this.sector,
    this.region,
    this.stage,
    this.type,
    this.sort = OpportunitySort.newest,
    this.search,
  });

  final String? sector;
  final String? region;
  final String? stage;
  final String? type;
  final OpportunitySort sort;
  final String? search;

  bool get isEmpty =>
      sector == null && region == null && stage == null && type == null && (search ?? '').isEmpty;

  int get activeCount =>
      [sector, region, stage, type].where((v) => v != null).length;

  Map<String, dynamic> toQuery({int page = 1, int perPage = 20}) => {
        'page': page,
        'per_page': perPage,
        'sort': sort.value,
        if (sector != null) 'filter[sector]': sector,
        if (region != null) 'filter[region]': region,
        if (stage != null) 'filter[stage]': stage,
        if (type != null) 'filter[type]': type,
        if ((search ?? '').isNotEmpty) 'search': search,
      };

  OpportunityFilters copyWith({
    String? sector,
    String? region,
    String? stage,
    String? type,
    OpportunitySort? sort,
    String? search,
    bool clearSector = false,
    bool clearRegion = false,
    bool clearStage = false,
    bool clearType = false,
  }) =>
      OpportunityFilters(
        sector: clearSector ? null : (sector ?? this.sector),
        region: clearRegion ? null : (region ?? this.region),
        stage: clearStage ? null : (stage ?? this.stage),
        type: clearType ? null : (type ?? this.type),
        sort: sort ?? this.sort,
        search: search ?? this.search,
      );

  static const empty = OpportunityFilters();
}

class OpportunitiesRepository {
  OpportunitiesRepository(this._api);

  final ApiClient _api;

  Future<Paginated<OpportunityCard>> list(OpportunityFilters f, {int page = 1}) async {
    final json = await _api.getJson('/opportunities', query: f.toQuery(page: page));
    return Paginated.fromJson(json, OpportunityCard.fromJson);
  }

  Future<OpportunityDetail> detail(String uuid) async {
    final json = await _api.getJson('/opportunities/$uuid');
    return OpportunityDetail.fromJson(asMap(json['data']).isEmpty ? json : asMap(json['data']));
  }

  Future<void> save(String uuid) => _api.postJson('/opportunities/$uuid/save');

  Future<void> unsave(String uuid) => _api.delete('/opportunities/$uuid/save');

  Future<Paginated<OpportunityCard>> saved({int page = 1}) async {
    final json = await _api.getJson('/investor/saved', query: {'page': page});
    return Paginated.fromJson(json, OpportunityCard.fromJson);
  }

  /// POST /opportunities/{uuid}/interest {note?} → sent → awaiting_owner_approval (INV-02).
  Future<Interest> expressInterest(String uuid, {String? note}) async {
    final json = await _api.postJson(
      '/opportunities/$uuid/interest',
      body: {if (note != null && note.isNotEmpty) 'note': note},
    );
    return Interest.fromJson(asMap(json['data']).isEmpty ? json : asMap(json['data']));
  }

  /// POST /opportunities/{uuid}/info-requests {subject, body} (OPP-05 — anonymous to owner).
  Future<InfoRequest> requestInfo(String uuid, {required String subject, required String body}) async {
    final json = await _api.postJson(
      '/opportunities/$uuid/info-requests',
      body: {'subject': subject, 'body': body},
    );
    return InfoRequest.fromJson(asMap(json['data']).isEmpty ? json : asMap(json['data']));
  }

  /// POST /opportunities/{uuid}/nda-accept {version} — consent before full file.
  Future<void> acceptNda(String uuid, {required String version}) =>
      _api.postJson('/opportunities/$uuid/nda-accept', body: {'version': version});

  Future<List<DocumentSummary>> documents(String uuid) async {
    final json = await _api.getJson('/opportunities/$uuid/documents');
    return listOfMaps(json['data']).map(DocumentSummary.fromJson).toList(growable: false);
  }

  /// GET /documents/{uuid}/download-url → {url, expires_at} (DOC-02, access logged).
  Future<({Uri url, DateTime? expiresAt})> downloadUrl(String documentUuid) async {
    final json = await _api.getJson('/documents/$documentUuid/download-url');
    return (url: Uri.parse(json['url'] as String), expiresAt: parseDate(json['expires_at']));
  }

  Future<Paginated<Interest>> interests({int page = 1}) async {
    final json = await _api.getJson('/investor/interests', query: {'page': page});
    return Paginated.fromJson(json, Interest.fromJson);
  }

  Future<void> closeInterest(int id, {required String reasonId, String? note}) =>
      _api.postJson(
        '/investor/interests/$id/close',
        body: {'reason_id': reasonId, if (note != null) 'note': note},
      );

  Future<Paginated<Nomination>> nominations({int page = 1}) async {
    final json = await _api.getJson('/investor/nominations', query: {'page': page});
    return Paginated.fromJson(json, Nomination.fromJson);
  }

  Future<void> respondNomination(
    int id, {
    required bool interested,
    String? declineReasonId,
    String? note,
  }) =>
      _api.postJson(
        '/investor/nominations/$id/respond',
        body: {
          'response': interested ? 'interested' : 'declined',
          if (declineReasonId != null) 'decline_reason_id': declineReasonId,
          if (note != null) 'note': note,
        },
      );

  Future<Map<String, List<TaxonomyTerm>>> taxonomies() async {
    final json = await _api.getJson('/taxonomies');
    final data = asMap(json['data']).isEmpty ? json : asMap(json['data']);
    return data.map((group, terms) => MapEntry(group, TaxonomyTerm.listFromJson(terms)));
  }
}

final opportunitiesRepositoryProvider = Provider<OpportunitiesRepository>(
  (ref) => OpportunitiesRepository(ref.watch(apiClientProvider)),
);

final taxonomiesProvider = FutureProvider<Map<String, List<TaxonomyTerm>>>(
  (ref) => ref.watch(opportunitiesRepositoryProvider).taxonomies(),
);

final opportunityFiltersProvider =
    StateProvider<OpportunityFilters>((ref) => OpportunityFilters.empty);

/// First page for the current filters; "load more" is handled in the screen.
final opportunitiesPageProvider = FutureProvider.autoDispose<Paginated<OpportunityCard>>(
  (ref) => ref.watch(opportunitiesRepositoryProvider).list(ref.watch(opportunityFiltersProvider)),
);

final opportunityDetailProvider =
    FutureProvider.autoDispose.family<OpportunityDetail, String>(
  (ref, uuid) => ref.watch(opportunitiesRepositoryProvider).detail(uuid),
);
