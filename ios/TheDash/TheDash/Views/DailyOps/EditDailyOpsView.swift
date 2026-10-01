import SwiftUI

struct EditDailyOpsView: View {
    let date: Date
    let current: DailyOpsDay?
    let locations: [LocationOption]
    let workLocations: [LocationOption]
    let onSaved: () async -> Void

    @Environment(\.dismiss) private var dismiss

    @State private var ckLocationID: Int?
    @State private var dcLocationID: Int?
    @State private var ckWorkID: Int?
    @State private var dcWorkID: Int?
    @State private var ckOnCall = false
    @State private var dcOnCall = false
    @State private var notes = ""
    @State private var isSaving = false

    var body: some View {
        NavigationStack {
            Form {
                Section("CK") {
                    Picker("Work Location", selection: $ckWorkID) {
                        Text("Not set").tag(nil as Int?)
                        ForEach(workLocations) { loc in
                            Text(loc.name).tag(loc.id as Int?)
                        }
                    }
                    Picker("Night Location", selection: $ckLocationID) {
                        Text("Not set").tag(nil as Int?)
                        ForEach(locations) { loc in
                            Text(loc.name).tag(loc.id as Int?)
                        }
                    }
                    Toggle("On Call", isOn: $ckOnCall)
                }

                Section("DC") {
                    Picker("Work Location", selection: $dcWorkID) {
                        Text("Not set").tag(nil as Int?)
                        ForEach(workLocations) { loc in
                            Text(loc.name).tag(loc.id as Int?)
                        }
                    }
                    Picker("Night Location", selection: $dcLocationID) {
                        Text("Not set").tag(nil as Int?)
                        ForEach(locations) { loc in
                            Text(loc.name).tag(loc.id as Int?)
                        }
                    }
                    Toggle("On Call", isOn: $dcOnCall)
                }

                Section("Meal / Notes") {
                    TextField("e.g. Chicken stir fry", text: $notes)
                }
            }
            .navigationTitle(DateFormatters.shortDay.string(from: date))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }
                        .disabled(isSaving)
                }
            }
            .onAppear { populateFromCurrent() }
        }
    }

    private func populateFromCurrent() {
        guard let ops = current else { return }
        ckLocationID = ops.ckLocation.id
        dcLocationID = ops.dcLocation.id
        ckWorkID = ops.ckWorkLocation.id
        dcWorkID = ops.dcWorkLocation.id
        ckOnCall = ops.ckOnCall
        dcOnCall = ops.dcOnCall
        notes = ops.notes
    }

    private func save() async {
        isSaving = true
        let dateStr = DateFormatters.apiDate.string(from: date)

        let request = UpdateDailyOpsRequest(
            date: dateStr,
            ckLocation: ckLocationID,
            dcLocation: dcLocationID,
            ckWorkLocation: ckWorkID,
            dcWorkLocation: dcWorkID,
            ckOnCall: ckOnCall,
            dcOnCall: dcOnCall,
            notes: notes
        )

        do {
            _ = try await APIService.shared.updateDailyOps(request)
            await onSaved()
            dismiss()
        } catch {
            print("Save ops error: \(error)")
        }
        isSaving = false
    }
}
