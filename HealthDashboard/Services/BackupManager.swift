import Foundation
import Observation
import UIKit

/// 백업 파일 하나에 앱 데이터 전부를 담는다.
/// - 앱 설정 (복약 · 습관 · 리포트 · 대시보드 배치 · 알림 설정 등) = UserDefaults 전체
/// - 앱 문서 폴더 전체 (식단 기록 · 식단 사진 · 건강검진 기록 등)
/// 건강 앱 · 캘린더 · 미리알림 데이터는 원래 iCloud에 있으므로 담지 않는다.
struct BackupArchive: Codable {
    var version = 1
    var createdAt: Date
    var appVersion: String
    var standardDefaults: Data
    var sharedDefaults: Data?
    /// 문서 폴더 기준 상대 경로 → 파일 내용
    var files: [String: Data]
}

/// iCloud Drive 폴더에 하루 한 번 자동 백업하고, 백업 파일로 복원한다. (무료 계정으로 동작)
@MainActor
@Observable
final class BackupManager {
    static let shared = BackupManager()

    private enum Keys {
        static let bookmark = "backupFolderBookmark"
        static let lastBackup = "lastBackupDate"
        static let folderName = "backupFolderName"
    }

    private static let filePrefix = "건강대시보드-백업-"
    private static let keepCount = 7

    private(set) var lastBackupDate: Date? = UserDefaults.standard.object(forKey: Keys.lastBackup) as? Date
    private(set) var folderName: String? = UserDefaults.standard.string(forKey: Keys.folderName)
    private(set) var isWorking = false
    var message: String?

    var hasFolder: Bool { UserDefaults.standard.data(forKey: Keys.bookmark) != nil }

    // MARK: - 폴더

    /// 파일 앱에서 고른 폴더(예: iCloud Drive/건강대시보드)를 기억해 둔다.
    func setFolder(_ url: URL) {
        let accessing = url.startAccessingSecurityScopedResource()
        defer { if accessing { url.stopAccessingSecurityScopedResource() } }
        do {
            let bookmark = try url.bookmarkData(options: [], includingResourceValuesForKeys: nil, relativeTo: nil)
            UserDefaults.standard.set(bookmark, forKey: Keys.bookmark)
            UserDefaults.standard.set(url.lastPathComponent, forKey: Keys.folderName)
            folderName = url.lastPathComponent
            message = "백업 폴더를 '\(url.lastPathComponent)'(으)로 정했어요."
        } catch {
            message = "폴더를 기억하지 못했어요: \(error.localizedDescription)"
        }
    }

    private func resolveFolder() -> URL? {
        guard let data = UserDefaults.standard.data(forKey: Keys.bookmark) else { return nil }
        var stale = false
        guard let url = try? URL(resolvingBookmarkData: data, options: [], relativeTo: nil, bookmarkDataIsStale: &stale) else {
            return nil
        }
        if stale, let fresh = try? url.bookmarkData(options: [], includingResourceValuesForKeys: nil, relativeTo: nil) {
            UserDefaults.standard.set(fresh, forKey: Keys.bookmark)
        }
        return url
    }

    // MARK: - 백업

    /// 폴더가 정해져 있고 마지막 백업이 20시간 넘게 지났으면 자동 백업
    func backupIfNeeded() async {
        guard hasFolder, !isWorking else { return }
        if let lastBackupDate, Date.now.timeIntervalSince(lastBackupDate) < 20 * 3600 { return }
        await backupNow(silent: true)
    }

    func backupNow(silent: Bool = false) async {
        guard let folder = resolveFolder() else {
            if !silent { message = "먼저 백업 폴더를 골라 주세요." }
            return
        }
        isWorking = true
        defer { isWorking = false }

        do {
            let archive = try Self.makeArchive()
            let name = Self.filePrefix + Date.now.formatted(.iso8601.year().month().day()) + ".json"
            try await Task.detached(priority: .utility) {
                let data = try JSONEncoder().encode(archive)
                let accessing = folder.startAccessingSecurityScopedResource()
                defer { if accessing { folder.stopAccessingSecurityScopedResource() } }
                try data.write(to: folder.appendingPathComponent(name), options: .atomic)
                Self.prune(folder: folder)
            }.value
            lastBackupDate = .now
            UserDefaults.standard.set(Date.now, forKey: Keys.lastBackup)
            if !silent { message = "백업했어요: \(name)" }
        } catch {
            message = "백업하지 못했어요: \(error.localizedDescription)"
        }
    }

    /// 오래된 백업은 최근 7개만 남기고 지운다.
    private nonisolated static func prune(folder: URL) {
        let fileManager = FileManager.default
        guard let files = try? fileManager.contentsOfDirectory(at: folder, includingPropertiesForKeys: nil) else { return }
        let backups = files.filter { $0.lastPathComponent.hasPrefix(filePrefix) }.sorted { $0.lastPathComponent > $1.lastPathComponent }
        for old in backups.dropFirst(keepCount) {
            try? fileManager.removeItem(at: old)
        }
    }

    private static var documents: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
    }

    private static func makeArchive() throws -> BackupArchive {
        let standard = UserDefaults.standard.persistentDomain(forName: Bundle.main.bundleIdentifier ?? "") ?? [:]
        let shared = SharedStore.appGroupID.flatMap { UserDefaults.standard.persistentDomain(forName: $0) }

        var files: [String: Data] = [:]
        let root = documents
        if let enumerator = FileManager.default.enumerator(at: root, includingPropertiesForKeys: [.isRegularFileKey]) {
            for case let url as URL in enumerator {
                guard (try? url.resourceValues(forKeys: [.isRegularFileKey]).isRegularFile) == true,
                      let data = try? Data(contentsOf: url) else { continue }
                let relative = url.path.replacingOccurrences(of: root.path + "/", with: "")
                files[relative] = data
            }
        }

        return BackupArchive(
            createdAt: .now,
            appVersion: Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "",
            standardDefaults: try PropertyListSerialization.data(fromPropertyList: standard, format: .binary, options: 0),
            sharedDefaults: try shared.map { try PropertyListSerialization.data(fromPropertyList: $0, format: .binary, options: 0) },
            files: files
        )
    }

    // MARK: - 복원

    func restore(from url: URL) async {
        isWorking = true
        defer { isWorking = false }

        let accessing = url.startAccessingSecurityScopedResource()
        defer { if accessing { url.stopAccessingSecurityScopedResource() } }

        do {
            let data = try Data(contentsOf: url)
            let archive = try JSONDecoder().decode(BackupArchive.self, from: data)

            // 1. 문서 폴더 (식단 기록 · 사진 등)
            let fileManager = FileManager.default
            let root = Self.documents
            for (relative, contents) in archive.files {
                let destination = root.appendingPathComponent(relative)
                try fileManager.createDirectory(at: destination.deletingLastPathComponent(), withIntermediateDirectories: true)
                try contents.write(to: destination, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            }

            // 2. 설정 (백업 폴더 정보는 지금 기기 것을 유지)
            let keepBookmark = UserDefaults.standard.data(forKey: Keys.bookmark)
            let keepFolderName = UserDefaults.standard.string(forKey: Keys.folderName)
            if var standard = try PropertyListSerialization.propertyList(from: archive.standardDefaults, format: nil) as? [String: Any],
               let bundleID = Bundle.main.bundleIdentifier {
                standard[Keys.bookmark] = keepBookmark
                standard[Keys.folderName] = keepFolderName
                UserDefaults.standard.setPersistentDomain(standard, forName: bundleID)
            }
            if let sharedData = archive.sharedDefaults, let groupID = SharedStore.appGroupID,
               let shared = try PropertyListSerialization.propertyList(from: sharedData, format: nil) as? [String: Any] {
                UserDefaults.standard.setPersistentDomain(shared, forName: groupID)
            }

            // 3. 앱 화면 다시 불러오기 · 알림 다시 예약
            await Self.reloadEverything()
            message = "\(archive.createdAt.formatted(date: .abbreviated, time: .shortened)) 백업으로 복원했어요."
        } catch {
            message = "복원하지 못했어요: \(error.localizedDescription)"
        }
    }

    private static func reloadEverything() async {
        MealStore.shared.reloadFromDisk()
        MedicationStore.shared.reloadFromDefaults()
        HabitStore.shared.reloadFromDefaults()
        WeeklyReportStore.shared.reloadFromDefaults()
        CheckupStore.shared.reloadFromDisk()
        KitchenStore.shared.profile = FamilyProfile.load()
        DashboardModel.shared.layout = DashboardLayout.load()
        await MedicationStore.shared.reschedule()
        await HabitStore.shared.rescheduleReminder()
        await DashboardModel.shared.refresh(force: true)
    }
}
